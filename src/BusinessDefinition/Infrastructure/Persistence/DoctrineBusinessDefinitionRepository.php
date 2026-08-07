<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionRevisionConflict;
use Kumwe\CMS\BusinessDefinition\Application\DefinitionCatalogEntry;
use Kumwe\CMS\BusinessDefinition\Application\DefinitionDraft;
use Kumwe\CMS\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\CMS\BusinessDefinition\Domain\CompatibilityChange;
use Kumwe\CMS\BusinessDefinition\Domain\CompatibilityClassification;
use Kumwe\CMS\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwnerType;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use LogicException;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class DoctrineBusinessDefinitionRepository implements BusinessDefinitionRepository
{
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    public function catalog(SiteContext $site): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? ORDER BY handle',
            $this->tables->quoted('business_definitions'),
        ), [$site->identifier()]);

        return array_map($this->mapEntry(...), $rows);
    }

    public function entry(SiteContext $site, string $identifier): ?DefinitionCatalogEntry
    {
        $row = $this->entryRow($site, $identifier);

        return $row === null ? null : $this->mapEntry($row);
    }

    public function draft(SiteContext $site, string $identifier): ?DefinitionDraft
    {
        $identity = Uuid::isValid($identifier) ? '(h.id = ? OR h.handle = ?)' : 'h.handle = ?';
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT d.* FROM %s d INNER JOIN %s h ON h.id = d.definition_id '
            . 'WHERE h.site_identifier = ? AND %s',
            $this->tables->quoted('business_definition_drafts'),
            $this->tables->quoted('business_definitions'),
            $identity,
        ), Uuid::isValid($identifier)
            ? [$site->identifier(), $identifier, $identifier]
            : [$site->identifier(), $identifier]);
        if ($row === false) {
            return null;
        }
        $definition = EntityTypeDefinition::fromArray($this->jsonObject($row, 'canonical_payload'));

        return new DefinitionDraft(
            $definition,
            $this->integer($row, 'revision'),
            $this->string($row, 'checksum'),
            $this->string($row, 'updated_by'),
            $this->date($row['updated_at'] ?? null),
        );
    }

    public function published(SiteContext $site, string $identifier, ?int $version = null): ?DefinitionVersionRecord
    {
        $identity = Uuid::isValid($identifier) ? '(h.id = ? OR h.handle = ?)' : 'h.handle = ?';
        $sql = sprintf(
            'SELECT v.* FROM %s v INNER JOIN %s h ON h.id = v.definition_id '
            . 'WHERE h.site_identifier = ? AND %s AND v.version = %s',
            $this->tables->quoted('business_definition_versions'),
            $this->tables->quoted('business_definitions'),
            $identity,
            $version === null ? 'h.published_version' : '?',
        );
        $parameters = Uuid::isValid($identifier)
            ? [$site->identifier(), $identifier, $identifier]
            : [$site->identifier(), $identifier];
        if ($version !== null) {
            $parameters[] = $version;
        }
        $row = $this->database->fetchAssociative($sql, $parameters);

        return $row === false ? null : $this->mapVersion($row);
    }

    public function history(SiteContext $site, string $identifier): array
    {
        $identity = Uuid::isValid($identifier) ? '(h.id = ? OR h.handle = ?)' : 'h.handle = ?';
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT v.* FROM %s v INNER JOIN %s h ON h.id = v.definition_id '
            . 'WHERE h.site_identifier = ? AND %s ORDER BY v.version DESC',
            $this->tables->quoted('business_definition_versions'),
            $this->tables->quoted('business_definitions'),
            $identity,
        ), Uuid::isValid($identifier)
            ? [$site->identifier(), $identifier, $identifier]
            : [$site->identifier(), $identifier]);

        return array_map($this->mapVersion(...), $rows);
    }

    public function saveDraft(
        EntityTypeDefinition $definition,
        string $actorId,
        DateTimeImmutable $now,
        ?int $expectedRevision,
    ): DefinitionDraft {
        $this->assertTransaction('Business-definition draft persistence');
        $row = $this->entryRow(SiteContext::fromString($definition->siteIdentifier), $definition->handle);
        if ($row === null) {
            if ($expectedRevision !== null && $expectedRevision !== 0) {
                throw new BusinessDefinitionRevisionConflict($expectedRevision, 0);
            }
            try {
                $this->database->insert($this->tables->raw('business_definitions'), [
                    'id' => $definition->id,
                    'site_identifier' => $definition->siteIdentifier,
                    'handle' => $definition->handle,
                    'owner_type' => $definition->owner->type->value,
                    'owner_identifier' => $definition->owner->identifier,
                    'owner_active' => true,
                    'draft_revision' => 1,
                    'published_version' => null,
                    'publication_state' => DefinitionStatus::Draft->value,
                    'created_by' => $actorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], [
                    'owner_active' => Types::BOOLEAN,
                    'draft_revision' => Types::INTEGER,
                    'published_version' => Types::INTEGER,
                    'created_at' => Types::DATETIME_IMMUTABLE,
                    'updated_at' => Types::DATETIME_IMMUTABLE,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                throw new BusinessDefinitionRevisionConflict($expectedRevision ?? 0, 1, $exception);
            }
            $revision = 1;
        } else {
            $this->assertSameOwner($definition, $row);
            $actual = $this->integer($row, 'draft_revision');
            if ($expectedRevision === null || $expectedRevision !== $actual) {
                throw new BusinessDefinitionRevisionConflict($expectedRevision ?? 0, $actual);
            }
            $revision = $actual + 1;
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET draft_revision = ?, publication_state = ?, updated_at = ? '
                . 'WHERE id = ? AND draft_revision = ?',
                $this->tables->quoted('business_definitions'),
            ), [
                $revision,
                DefinitionStatus::Draft->value,
                $now,
                $this->string($row, 'id'),
                $actual,
            ], [Types::INTEGER, Types::STRING, Types::DATETIME_IMMUTABLE, Types::GUID, Types::INTEGER]);
            if ($affected !== 1) {
                throw new BusinessDefinitionRevisionConflict($actual, $this->headDraftRevision($definition->id));
            }
        }
        $payload = $definition->toArray();
        $values = [
            'revision' => $revision,
            'checksum' => $definition->checksum(),
            'canonical_payload' => $payload,
            'dependency_graph' => $definition->dependencyGraph(),
            'updated_by' => $actorId,
            'updated_at' => $now,
        ];
        $types = [
            'revision' => Types::INTEGER,
            'canonical_payload' => Types::JSON,
            'dependency_graph' => Types::JSON,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];
        $affected = $this->database->update(
            $this->tables->raw('business_definition_drafts'),
            $values,
            ['definition_id' => $definition->id],
            $types,
        );
        if ($affected === 0) {
            $this->database->insert($this->tables->raw('business_definition_drafts'), [
                'definition_id' => $definition->id,
                ...$values,
            ], ['definition_id' => Types::GUID, ...$types]);
        }

        return new DefinitionDraft($definition, $revision, $definition->checksum(), $actorId, $now);
    }

    public function publish(
        EntityTypeDefinition $definition,
        CompatibilityPlan $plan,
        string $actorId,
        DateTimeImmutable $now,
        int $expectedDraftRevision,
    ): DefinitionVersionRecord {
        $this->assertTransaction('Business-definition publication');
        if (
            !hash_equals($definition->checksum(), $plan->toChecksum)
            || $definition->definitionVersion !== $plan->toVersion
        ) {
            throw new InvalidBusinessDefinition('A publication does not match its compatibility plan.');
        }
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET draft_revision = 0, published_version = ?, publication_state = ?, updated_at = ? '
            . 'WHERE id = ? AND draft_revision = ?',
            $this->tables->quoted('business_definitions'),
        ), [
            $definition->definitionVersion,
            DefinitionStatus::Published->value,
            $now,
            $definition->id,
            $expectedDraftRevision,
        ], [Types::INTEGER, Types::STRING, Types::DATETIME_IMMUTABLE, Types::GUID, Types::INTEGER]);
        if ($affected !== 1) {
            throw new BusinessDefinitionRevisionConflict(
                $expectedDraftRevision,
                $this->headDraftRevision($definition->id),
            );
        }
        $previous = $definition->definitionVersion - 1;
        if ($previous > 0) {
            $this->database->update($this->tables->raw('business_definition_versions'), [
                'status' => DefinitionStatus::Superseded->value,
            ], [
                'definition_id' => $definition->id,
                'version' => $previous,
                'status' => DefinitionStatus::Published->value,
            ]);
        }
        $this->database->insert($this->tables->raw('business_definition_versions'), [
            'definition_id' => $definition->id,
            'version' => $definition->definitionVersion,
            'status' => $definition->status->value,
            'checksum' => $definition->checksum(),
            'canonical_payload' => $definition->toArray(),
            'dependency_graph' => $definition->dependencyGraph(),
            'compatibility_plan' => $plan->toArray(),
            'published_by' => $actorId,
            'published_at' => $now,
        ], [
            'version' => Types::INTEGER,
            'canonical_payload' => Types::JSON,
            'dependency_graph' => Types::JSON,
            'compatibility_plan' => Types::JSON,
            'published_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $this->replaceDependencies($definition);
        $this->database->delete($this->tables->raw('business_definition_drafts'), [
            'definition_id' => $definition->id,
        ]);

        return new DefinitionVersionRecord($definition, $plan, DefinitionStatus::Published, $actorId, $now);
    }

    public function changeStatus(
        SiteContext $site,
        string $identifier,
        int $version,
        DefinitionStatus $status,
        DateTimeImmutable $now,
    ): DefinitionVersionRecord {
        $this->assertTransaction('Business-definition status mutation');
        if (
            !in_array(
                $status,
                [DefinitionStatus::Superseded, DefinitionStatus::Deprecated, DefinitionStatus::Rejected],
                true,
            )
        ) {
            throw new InvalidBusinessDefinition('The requested definition status transition is unsupported.');
        }
        $entry = $this->entryRow($site, $identifier)
            ?? throw new InvalidBusinessDefinition('The business definition does not exist.');
        $affected = $this->database->update($this->tables->raw('business_definition_versions'), [
            'status' => $status->value,
        ], ['definition_id' => $this->string($entry, 'id'), 'version' => $version]);
        if ($affected !== 1) {
            throw new InvalidBusinessDefinition('The requested business definition version does not exist.');
        }
        if ($this->integerOrNull($entry, 'published_version') === $version) {
            $this->database->update($this->tables->raw('business_definitions'), [
                'publication_state' => $status->value,
                'updated_at' => $now,
            ], ['id' => $this->string($entry, 'id')], ['updated_at' => Types::DATETIME_IMMUTABLE]);
        }

        return $this->published($site, $identifier, $version)
            ?? throw new RuntimeException('The changed business definition could not be reloaded.');
    }

    public function setOwnerActive(string $ownerIdentifier, bool $active, DateTimeImmutable $now): void
    {
        $this->assertTransaction('Business-definition owner lifecycle synchronization');
        $this->database->update($this->tables->raw('business_definitions'), [
            'owner_active' => $active,
            'updated_at' => $now,
        ], ['owner_type' => DefinitionOwnerType::Extension->value, 'owner_identifier' => $ownerIdentifier], [
            'owner_active' => Types::BOOLEAN,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $this->database->update($this->tables->raw('business_field_types'), [
            'active' => $active,
            'updated_at' => $now,
        ], ['owner_type' => DefinitionOwnerType::Extension->value, 'owner_identifier' => $ownerIdentifier], [
            'active' => Types::BOOLEAN,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function mapEntry(array $row): DefinitionCatalogEntry
    {
        $ownerType = DefinitionOwnerType::tryFrom($this->string($row, 'owner_type'))
            ?? throw new RuntimeException('Stored business-definition owner type is invalid.');
        $status = DefinitionStatus::tryFrom($this->string($row, 'publication_state'))
            ?? throw new RuntimeException('Stored business-definition publication state is invalid.');

        return new DefinitionCatalogEntry(
            $this->string($row, 'id'),
            $this->string($row, 'site_identifier'),
            $this->string($row, 'handle'),
            new DefinitionOwner($ownerType, $this->string($row, 'owner_identifier')),
            $this->boolean($row, 'owner_active'),
            $this->integer($row, 'draft_revision'),
            $this->integerOrNull($row, 'published_version'),
            $status,
            $this->date($row['updated_at'] ?? null),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapVersion(array $row): DefinitionVersionRecord
    {
        $definition = EntityTypeDefinition::fromArray($this->jsonObject($row, 'canonical_payload'));
        $plan = $this->jsonObject($row, 'compatibility_plan');
        $changes = [];
        foreach ($this->arrayList($plan, 'changes') as $change) {
            if (!is_array($change) || array_is_list($change)) {
                throw new RuntimeException('Stored business compatibility change is invalid.');
            }
            /** @var non-empty-array<string, mixed> $change */
            $classification = CompatibilityClassification::tryFrom($this->string($change, 'classification'))
                ?? throw new RuntimeException('Stored compatibility classification is invalid.');
            $changes[] = new CompatibilityChange(
                $this->string($change, 'path'),
                $classification,
                $this->string($change, 'message'),
            );
        }
        $compatibility = new CompatibilityPlan(
            $this->integerOrNull($plan, 'from_version'),
            $this->integer($plan, 'to_version'),
            $this->nullableString($plan, 'from_checksum'),
            $this->string($plan, 'to_checksum'),
            $changes,
        );

        return new DefinitionVersionRecord(
            $definition,
            $compatibility,
            DefinitionStatus::tryFrom($this->string($row, 'status'))
                ?? throw new RuntimeException('Stored definition-version status is invalid.'),
            $this->string($row, 'published_by'),
            $this->date($row['published_at'] ?? null),
        );
    }

    private function replaceDependencies(EntityTypeDefinition $definition): void
    {
        $this->database->delete($this->tables->raw('business_definition_dependencies'), [
            'definition_id' => $definition->id,
            'version' => $definition->definitionVersion,
        ]);
        $graph = $definition->dependencyGraph();
        foreach ($graph['fields'] as $source => $targets) {
            foreach ($targets as $target) {
                $this->insertDependency($definition, 'field', $source . '>' . $target);
            }
        }
        foreach (['entity' => 'entities', 'field_type' => 'field_types'] as $kind => $collection) {
            foreach ($graph[$collection] as $handle) {
                $this->insertDependency($definition, $kind, $handle);
            }
        }
    }

    private function insertDependency(EntityTypeDefinition $definition, string $kind, string $handle): void
    {
        $this->database->insert($this->tables->raw('business_definition_dependencies'), [
            'definition_id' => $definition->id,
            'version' => $definition->definitionVersion,
            'dependency_kind' => $kind,
            'dependency_handle' => $handle,
            'owner_identifier' => $definition->owner->identifier,
        ], ['version' => Types::INTEGER]);
    }

    /** @return array<string, mixed>|null */
    private function entryRow(SiteContext $site, string $identifier): ?array
    {
        $identity = Uuid::isValid($identifier) ? '(id = ? OR handle = ?)' : 'handle = ?';
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? AND %s',
            $this->tables->quoted('business_definitions'),
            $identity,
        ), Uuid::isValid($identifier)
            ? [$site->identifier(), $identifier, $identifier]
            : [$site->identifier(), $identifier]);

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $row */
    private function assertSameOwner(EntityTypeDefinition $definition, array $row): void
    {
        if (
            $this->string($row, 'id') !== $definition->id
            || $this->string($row, 'owner_type') !== $definition->owner->type->value
            || $this->string($row, 'owner_identifier') !== $definition->owner->identifier
        ) {
            throw new InvalidBusinessDefinition('A business-definition identity or owner cannot be changed.');
        }
    }

    private function headDraftRevision(string $id): int
    {
        $value = $this->database->fetchOne(sprintf(
            'SELECT draft_revision FROM %s WHERE id = ?',
            $this->tables->quoted('business_definitions'),
        ), [$id]);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function assertTransaction(string $operation): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new LogicException($operation . ' requires an active transaction.');
        }
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored business-definition property ' . $key . ' is invalid.');
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new RuntimeException('Stored business-definition property ' . $key . ' is invalid.');
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException('Stored business-definition property ' . $key . ' is invalid.');
        }
        return (int) $value;
    }

    /** @param array<string, mixed> $row */
    private function integerOrNull(array $row, string $key): ?int
    {
        return ($row[$key] ?? null) === null ? null : $this->integer($row, $key);
    }

    /** @param array<string, mixed> $row */
    private function boolean(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [0, 1, '0', '1'], true)) {
            return (bool) $value;
        }
        throw new RuntimeException('Stored business-definition property ' . $key . ' is invalid.');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function jsonObject(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Stored business-definition JSON is invalid.', 0, $exception);
            }
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('Stored business-definition property ' . $key . ' must be an object.');
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<mixed>
     */
    private function arrayList(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Stored business-definition property ' . $key . ' must be a list.');
        }
        return $value;
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value)) {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }
        throw new RuntimeException('Stored business-definition timestamp is invalid.');
    }
}
