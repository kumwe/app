<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use JsonException;
use Kumwe\App\BusinessDefinition\Application\FieldTypeDefinitionResolver;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwnerType;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\Infrastructure\Persistence\TableNames;

/**
 * Resolves core types in memory and contributed structure from checksum-verified persisted history.
 *
 * This is the `FieldTypeDefinitionResolver` the physical schema compiler holds, and its whole point is
 * that structure outlives execution: rows already written under a contributed field type still have to be
 * described and migrated after the extension that declared it was disabled or removed. Lookups therefore
 * ignore the `active` flag on `business_field_types` and answer from the stored row. Only `core.*`
 * identifiers are served from the in-memory `FieldTypeRegistry`, because the built-ins ship with the
 * platform and have no stored history to verify; for every other identifier the persisted row stays
 * authoritative even when the same type is registered in this process. A row is accepted only once its
 * payload decodes to a JSON object, the stored identifier and the payload's own id both match the one
 * asked for, the stored checksum still matches the canonical encoding of that payload, and the recorded
 * owner's namespace covers the identifier. Every one of those checks fails closed with
 * `InvalidBusinessDefinition` rather than degrading to a default shape.
 *
 * @since  2.0.0
 */
final readonly class DoctrinePersistedFieldTypeDefinitionResolver implements FieldTypeDefinitionResolver
{
    /**
     * Bind the resolver to the field-type history table and to the in-memory core set.
     *
     * @param  Connection         $database  Connection the `business_field_types` history is read on.
     * @param  TableNames         $tables    Physical name compiler for `business_field_types`.
     * @param  FieldTypeRegistry  $active    Contribution set consulted for `core.*` identifiers only; a
     *         contributed type registered here is still read from, and verified against, its stored row.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private FieldTypeRegistry $active,
    ) {
    }

    /**
     * Resolve a field type from the platform's built-ins or from verified persisted history.
     *
     * A `core.*` identifier is answered from the in-memory registry without touching the database;
     * anything else is read from `business_field_types` whatever its activation state, so a withdrawn
     * owner's structure stays resolvable for the records still stored under it.
     *
     * @param   string  $identifier  Namespaced field-type identifier, such as `core.text` or
     *          `vendor.package.value`.
     *
     * @return  FieldTypeDefinition  The structure the identifier was published with, re-verified against
     *          the checksum stored beside it.
     *
     * @throws  InvalidBusinessDefinition  When a `core.*` identifier is not registered in this process;
     *          when no row carries the identifier; when the stored payload is not a JSON object; when the
     *          row's identifier or the payload's own id disagrees with the one asked for; when the stored
     *          checksum no longer matches the payload; or when the recorded owner is unreadable, names an
     *          unknown owner type, or does not own the identifier.
     *
     * @since   2.0.0
     */
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
