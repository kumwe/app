<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use JsonException;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeDefinitionResolver;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwnerType;
use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;

/** Resolves core types in memory and contributed structure from checksum-verified persisted history. */
final readonly class DoctrinePersistedFieldTypeDefinitionResolver implements FieldTypeDefinitionResolver
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private FieldTypeRegistry $active,
    ) {
    }

    public function get(string $identifier): FieldTypeDefinition
    {
        if (str_starts_with($identifier, 'core.')) {
            return $this->active->get($identifier);
        }

        $row = $this->database->fetchAssociative(sprintf(
            'SELECT identifier, owner_type, owner_identifier, checksum, canonical_payload FROM %s '
            . 'WHERE identifier = ?',
            $this->tables->quoted('business_field_types'),
        ), [$identifier]);
        if ($row === false) {
            throw new InvalidBusinessDefinition('Field type ' . $identifier . ' is not structurally available.');
        }

        $payload = $row['canonical_payload'] ?? null;
        if (is_string($payload)) {
            try {
                $payload = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidBusinessDefinition('A persisted field-type payload is invalid.', 0, $exception);
            }
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidBusinessDefinition('A persisted field-type payload is invalid.');
        }
        /** @var array<string, mixed> $payload */
        $definition = FieldTypeDefinition::fromArray($payload);
        $persistedIdentifier = $row['identifier'] ?? null;
        if (
            !is_string($persistedIdentifier) || $persistedIdentifier !== $identifier
            || $definition->id !== $identifier
        ) {
            throw new InvalidBusinessDefinition('A persisted field-type identifier is inconsistent.');
        }
        $checksum = $row['checksum'] ?? null;
        if (
            !is_string($checksum)
            || !hash_equals($checksum, CanonicalDefinitionJson::checksum($definition->toArray()))
        ) {
            throw new InvalidBusinessDefinition('A persisted field-type checksum is invalid.');
        }
        $ownerTypeValue = $row['owner_type'] ?? null;
        $ownerIdentifier = $row['owner_identifier'] ?? null;
        if (!is_string($ownerTypeValue) || !is_string($ownerIdentifier)) {
            throw new InvalidBusinessDefinition('A persisted field-type owner is invalid.');
        }
        $ownerType = DefinitionOwnerType::tryFrom($ownerTypeValue)
            ?? throw new InvalidBusinessDefinition('A persisted field-type owner is invalid.');
        (new DefinitionOwner($ownerType, $ownerIdentifier))->assertOwns($definition->id);

        return $definition;
    }
}
