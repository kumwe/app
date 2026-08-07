<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionCompatibilityAnalyzer;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\CMS\BusinessDefinition\Application\PackageDefinitionSynchronizer;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwnerType;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use LogicException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

final readonly class DoctrinePackageDefinitionSynchronizer implements PackageDefinitionSynchronizer
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private BusinessDefinitionRepository $repository,
        private BusinessDefinitionCompatibilityAnalyzer $compatibility,
        private ResourceSiteOwnershipWriter $ownership,
        private AuditRecorder $audit,
        private ClockInterface $clock,
    ) {
    }

    public function synchronize(
        string $extensionIdentifier,
        string $releaseVersion,
        SiteContext $site,
        array $fieldTypes,
        array $definitions,
        bool $active,
        string $actorId,
    ): void {
        $this->assertTransaction();
        $owner = DefinitionOwner::extension($extensionIdentifier);
        $definitions = array_map(
            static function (EntityTypeDefinition $definition) use ($site): EntityTypeDefinition {
                $document = $definition->toArray();
                $document['site'] = $site->identifier();
                return EntityTypeDefinition::fromArray($document);
            },
            $definitions,
        );
        $validationTypes = new FieldTypeRegistry();
        foreach ($this->activePersistedFieldTypes() as [$persistedOwner, $fieldType]) {
            if ($persistedOwner == $owner) {
                continue;
            }
            if (!$validationTypes->has($fieldType->id)) {
                $validationTypes->register($persistedOwner, $fieldType);
            }
        }
        foreach ($fieldTypes as $fieldType) {
            $owner->assertOwns($fieldType->id);
            if (!$validationTypes->has($fieldType->id)) {
                $validationTypes->register($owner, $fieldType);
            }
        }
        foreach ($definitions as $definition) {
            if ($definition->owner != $owner || $definition->status !== DefinitionStatus::Published) {
                throw new InvalidBusinessDefinition('A package definition has invalid ownership, site, or status.');
            }
        }
        $resultingGraph = $this->existingDefinitionGraph($site, $owner, $definitions);
        if ($resultingGraph !== []) {
            (new BusinessDefinitionValidator($validationTypes))->validateGraph($resultingGraph);
        }
        $this->synchronizeFieldTypes($owner, $releaseVersion, $fieldTypes, $active);
        $this->synchronizeDefinitions($owner, $site, $definitions, $actorId);
        $this->repository->setOwnerActive($extensionIdentifier, $active, $this->clock->now());
        $this->record($actorId, 'business_definition.package.synchronize', $extensionIdentifier, [
            'release_version' => $releaseVersion,
            'field_types' => count($fieldTypes),
            'definitions' => count($definitions),
            'active' => $active,
        ]);
    }

    public function setActive(string $extensionIdentifier, bool $active, string $actorId): void
    {
        $this->assertTransaction();
        $this->repository->setOwnerActive($extensionIdentifier, $active, $this->clock->now());
        $this->record(
            $actorId,
            $active ? 'business_definition.package.activate' : 'business_definition.package.disable',
            $extensionIdentifier,
            ['active' => $active],
        );
    }

    /** @param list<FieldTypeDefinition> $definitions */
    private function synchronizeFieldTypes(
        DefinitionOwner $owner,
        string $releaseVersion,
        array $definitions,
        bool $active,
    ): void {
        $declared = [];
        foreach ($definitions as $definition) {
            $declared[] = $definition->id;
            $payload = $definition->toArray();
            $checksum = CanonicalDefinitionJson::checksum($payload);
            $existing = $this->database->fetchAssociative(sprintf(
                'SELECT owner_type, owner_identifier, checksum FROM %s WHERE identifier = ?',
                $this->tables->quoted('business_field_types'),
            ), [$definition->id]);
            if ($existing !== false) {
                if (($existing['owner_type'] ?? null) !== DefinitionOwnerType::Extension->value
                    || ($existing['owner_identifier'] ?? null) !== $owner->identifier) {
                    throw new InvalidBusinessDefinition('A package attempted to claim another owner\'s field type.');
                }
                if (!is_string($existing['checksum'] ?? null) || !hash_equals($existing['checksum'], $checksum)) {
                    throw new InvalidBusinessDefinition(
                        'Published field type ' . $definition->id . ' is immutable; declare a new identifier.',
                    );
                }
                $this->database->update($this->tables->raw('business_field_types'), [
                    'source_version' => $releaseVersion,
                    'active' => $active,
                    'updated_at' => $this->clock->now(),
                ], ['identifier' => $definition->id], [
                    'active' => Types::BOOLEAN,
                    'updated_at' => Types::DATETIME_IMMUTABLE,
                ]);
                continue;
            }
            $this->database->insert($this->tables->raw('business_field_types'), [
                'identifier' => $definition->id,
                'owner_type' => DefinitionOwnerType::Extension->value,
                'owner_identifier' => $owner->identifier,
                'source_version' => $releaseVersion,
                'active' => $active,
                'checksum' => $checksum,
                'canonical_payload' => $payload,
                'updated_at' => $this->clock->now(),
            ], [
                'active' => Types::BOOLEAN,
                'canonical_payload' => Types::JSON,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
        }
        $owned = $this->database->fetchFirstColumn(sprintf(
            'SELECT identifier FROM %s WHERE owner_type = ? AND owner_identifier = ? ORDER BY identifier',
            $this->tables->quoted('business_field_types'),
        ), [DefinitionOwnerType::Extension->value, $owner->identifier]);
        foreach ($owned as $identifier) {
            if (is_string($identifier) && !in_array($identifier, $declared, true)) {
                $this->database->update($this->tables->raw('business_field_types'), [
                    'active' => false,
                    'updated_at' => $this->clock->now(),
                ], ['identifier' => $identifier], [
                    'active' => Types::BOOLEAN,
                    'updated_at' => Types::DATETIME_IMMUTABLE,
                ]);
            }
        }
    }

    /** @param list<EntityTypeDefinition> $definitions */
    private function synchronizeDefinitions(
        DefinitionOwner $owner,
        SiteContext $site,
        array $definitions,
        string $actorId,
    ): void {
        $declared = [];
        foreach ($definitions as $definition) {
            $declared[] = $definition->handle;
            $existing = $this->repository->published($site, $definition->handle);
            if ($existing !== null && $existing->definition->definitionVersion === $definition->definitionVersion) {
                if (!hash_equals($existing->definition->checksum(), $definition->checksum())) {
                    throw new InvalidBusinessDefinition('Applied package definition bytes are immutable.');
                }
                continue;
            }
            $expectedVersion = ($existing?->definition->definitionVersion ?? 0) + 1;
            if ($definition->definitionVersion !== $expectedVersion) {
                throw new InvalidBusinessDefinition(sprintf(
                    'Package definition %s must publish version %d next.',
                    $definition->handle,
                    $expectedVersion,
                ));
            }
            $draftDocument = $definition->toArray();
            $draftDocument['status'] = DefinitionStatus::Draft->value;
            $draftDocument['definition_version'] = 0;
            $draft = EntityTypeDefinition::fromArray($draftDocument);
            $entry = $this->repository->entry($site, $draft->handle);
            if ($entry !== null && $entry->owner != $owner) {
                throw new InvalidBusinessDefinition('A package attempted to replace another owner\'s definition.');
            }
            $saved = $this->repository->saveDraft(
                $draft,
                $actorId,
                $this->clock->now(),
                $entry?->draftRevision,
            );
            if ($entry === null) {
                $this->ownership->record(
                    AuthorizationResource::item('business_definition', $draft->id),
                    $site,
                );
            }
            $plan = $this->compatibility->analyze($existing?->definition, $draft);
            $this->repository->publish(
                $definition,
                $plan,
                $actorId,
                $this->clock->now(),
                $saved->revision,
            );
        }
        foreach ($this->repository->catalog($site) as $entry) {
            if ($entry->owner != $owner || in_array($entry->handle, $declared, true)
                || $entry->publishedVersion === null) {
                continue;
            }
            $this->repository->changeStatus(
                $site,
                $entry->id,
                $entry->publishedVersion,
                DefinitionStatus::Deprecated,
                $this->clock->now(),
            );
        }
    }

    /** @return list<array{0: DefinitionOwner, 1: FieldTypeDefinition}> */
    private function activePersistedFieldTypes(): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT owner_type, owner_identifier, canonical_payload FROM %s WHERE active = ? ORDER BY identifier',
            $this->tables->quoted('business_field_types'),
        ), [true], [Types::BOOLEAN]);
        $result = [];
        foreach ($rows as $row) {
            $ownerType = DefinitionOwnerType::tryFrom((string) ($row['owner_type'] ?? ''))
                ?? throw new InvalidBusinessDefinition('A persisted field-type owner is invalid.');
            $identifier = $row['owner_identifier'] ?? null;
            $payload = $row['canonical_payload'] ?? null;
            if (!is_string($identifier)) {
                throw new InvalidBusinessDefinition('A persisted field-type owner identifier is invalid.');
            }
            if (is_string($payload)) {
                $payload = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
            }
            if (!is_array($payload) || array_is_list($payload)) {
                throw new InvalidBusinessDefinition('A persisted field-type payload is invalid.');
            }
            /** @var array<string, mixed> $payload */
            $result[] = [new DefinitionOwner($ownerType, $identifier), FieldTypeDefinition::fromArray($payload)];
        }
        return $result;
    }

    /**
     * @param list<EntityTypeDefinition> $packageDefinitions
     * @return list<EntityTypeDefinition>
     */
    private function existingDefinitionGraph(
        SiteContext $site,
        DefinitionOwner $packageOwner,
        array $packageDefinitions,
    ): array {
        $handles = array_map(static fn (EntityTypeDefinition $item): string => $item->handle, $packageDefinitions);
        $graph = $packageDefinitions;
        foreach ($this->repository->catalog($site) as $entry) {
            if ($entry->publishedVersion === null || $entry->owner == $packageOwner
                || in_array($entry->handle, $handles, true)) {
                continue;
            }
            $published = $this->repository->published($site, $entry->id);
            if ($published !== null && $entry->ownerActive) {
                $graph[] = $published->definition;
            }
        }
        return $graph;
    }

    /** @param array<string, mixed> $metadata */
    private function record(string $actorId, string $action, string $subject, array $metadata): void
    {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $actorId,
            $action,
            'business_definition',
            $subject,
            'success',
            $metadata,
        ));
    }

    private function assertTransaction(): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new LogicException(
                'Package definition synchronization requires the extension lifecycle transaction.',
            );
        }
    }
}
