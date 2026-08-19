<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Installation store holding one row per definition in the prefixed `business_schema_installations` table.
 *
 * The definition ID is the table's primary key, which is why a lookup here names no site: the row that
 * comes back may belong to another site's copy of the definition, and every reader compares the record's
 * own site and owner before acting on it. The two writes are deliberately driver-neutral. `save()`
 * updates first and inserts only after proving the key is genuinely absent, because MySQL reports zero
 * affected rows for an update that leaves every column unchanged and a blind insert would then collide
 * with itself. `ownedByForUpdate()` refuses to run outside a transaction, so the `FOR UPDATE` lock it
 * takes outlives the statement that took it and the lifecycle sweep really does own the rows it is about
 * to rewrite. Driver rows arrive untyped, so every column is re-checked as it is mapped and
 * `SchemaInstallation` re-proves the stored blueprint against its recorded checksums; a hand-edited row
 * is refused here rather than handed to the record layer that decides whether tables may be used.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessSchemaInstallationRepository implements BusinessSchemaInstallationRepository
{
    /**
     * Bind the store to the connection its statements run on and the resolver that names the table.
     *
     * @param  Connection  $database  DBAL connection carrying the caller's transaction, when one is open.
     * @param  TableNames  $tables    Resolver applying the configured prefix to `business_schema_installations`.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Read the installation row recorded for one business definition.
     *
     * Not site-scoped, because the definition ID is the whole key: a caller holding a site context has to
     * compare the returned `siteIdentifier` against it rather than assume the row is its own.
     *
     * @param   string  $definitionId  UUID of the business definition whose installed schema is wanted.
     *
     * @return  ?SchemaInstallation  The stored installation, or null when that definition has no row.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     * @throws  RuntimeException  When a stored column is absent, empty, or holds the wrong type.
     * @throws  \Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema  When the row no longer satisfies the
     *          installation invariants, such as a blueprint that disagrees with its recorded checksum.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When a stored table's options
     *          cannot be canonically encoded.
     *
     * @since   2.0.0
     */
    public function find(string $definitionId): ?SchemaInstallation
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE definition_id = ?',
            $this->tables->quoted('business_schema_installations'),
        ), [$definitionId]);
        if ($row === false) {
            return null;
        }

        return $this->map($row);
    }

    /**
     * Read installation rows in bounded driver-safe batches.
     *
     * @param   list<string>  $definitionIds  Unique canonical definition UUIDs, at most 4096.
     *
     * @return  array<string, SchemaInstallation>  Revalidated rows keyed by definition UUID.
     *
     * @throws  \InvalidArgumentException  When the request is malformed, duplicated, or over its bound.
     * @throws  RuntimeException  When storage returns a duplicate or malformed installation.
     *
     * @since   2.0.0
     */
    public function findBatch(array $definitionIds): array
    {
        if (!array_is_list($definitionIds) || count($definitionIds) > 4096) {
            throw new \InvalidArgumentException('A schema-installation batch is malformed or unbounded.');
        }
        $seen = [];
        foreach ($definitionIds as $definitionId) {
            if (!is_string($definitionId) || !Uuid::isValid($definitionId) || isset($seen[$definitionId])) {
                throw new \InvalidArgumentException('A schema-installation batch contains an invalid identity.');
            }
            $seen[$definitionId] = true;
        }
        $installations = [];
        foreach (array_chunk($definitionIds, 500) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $rows = $this->database->fetchAllAssociative(sprintf(
                'SELECT * FROM %s WHERE definition_id IN (?)',
                $this->tables->quoted('business_schema_installations'),
            ), [$chunk], [ArrayParameterType::STRING]);
            foreach ($rows as $row) {
                $installation = $this->map($row);
                if (isset($installations[$installation->definitionId])) {
                    throw new RuntimeException('A schema-installation batch returned a duplicate identity.');
                }
                $installations[$installation->definitionId] = $installation;
            }
        }

        return $installations;
    }

    /**
     * Rebuild one installation from a driver row, proving each column before the domain sees it.
     *
     * The blueprint column and both timestamps are normalized to the text shapes
     * `SchemaInstallation::fromArray()` accepts, which then revalidates the record as a whole.
     *
     * @param   array<string, mixed>  $row  One associative row of `business_schema_installations`.
     *
     * @return  SchemaInstallation  The revalidated installation the row describes.
     *
     * @throws  RuntimeException  When a column is absent, empty, wrongly typed, or holds invalid JSON.
     * @throws  \Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema  When the assembled document breaks an
     *          installation invariant, or the stored status is not one this build knows.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When a stored table's options
     *          cannot be canonically encoded.
     *
     * @since   2.0.0
     */
    private function map(array $row): SchemaInstallation
    {
        return SchemaInstallation::fromArray([
            'definition_id' => $this->string($row, 'definition_id'),
            'site_identifier' => $this->string($row, 'site_identifier'),
            'owner_identifier' => $this->string($row, 'owner_identifier'),
            'definition_version' => $this->integer($row, 'definition_version'),
            'definition_checksum' => $this->string($row, 'definition_checksum'),
            'schema_checksum' => $this->string($row, 'schema_checksum'),
            'blueprint' => $this->jsonObject($row['blueprint'] ?? null),
            'status' => $this->string($row, 'status'),
            'installed_at' => $this->date($row['installed_at'] ?? null),
            'updated_at' => $this->date($row['updated_at'] ?? null),
        ]);
    }

    /**
     * Make an installation the current record for its definition, inserting it when no row exists yet.
     *
     * Written as an UPDATE followed by a conditional INSERT rather than a driver-specific upsert. An
     * update that matched nothing is not proof the row is missing — MySQL reports zero affected rows for a
     * write that leaves every column unchanged — so the key is probed first and the insert runs only when
     * it is genuinely absent, which makes re-saving identical state harmless. Identity is the definition
     * ID, so a save overwrites the recorded shape, status, and timestamps instead of appending history.
     *
     * @param   SchemaInstallation  $installation  Installation state to store; its `definitionId` is the key.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the update, the key probe, or the insert,
     *          including when a concurrent writer inserted the same definition first.
     *
     * @since   2.0.0
     */
    public function save(SchemaInstallation $installation): void
    {
        $values = [
            'site_identifier' => $installation->siteIdentifier,
            'owner_identifier' => $installation->ownerIdentifier,
            'definition_version' => $installation->definitionVersion,
            'definition_checksum' => $installation->definitionChecksum,
            'schema_checksum' => $installation->schemaChecksum,
            'blueprint' => $installation->blueprint->toArray(),
            'status' => $installation->status->value,
            'installed_at' => $installation->installedAt,
            'updated_at' => $installation->updatedAt,
        ];
        $types = [
            'definition_version' => Types::INTEGER,
            'blueprint' => Types::JSON,
            'installed_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];
        $affected = $this->database->update(
            $this->tables->raw('business_schema_installations'),
            $values,
            ['definition_id' => $installation->definitionId],
            $types,
        );
        if ($affected === 0) {
            $exists = $this->database->fetchOne(sprintf(
                'SELECT definition_id FROM %s WHERE definition_id = ?',
                $this->tables->quoted('business_schema_installations'),
            ), [$installation->definitionId]);
            if ($exists !== false) {
                return;
            }
            $this->database->insert($this->tables->raw('business_schema_installations'), [
                'definition_id' => $installation->definitionId,
                ...$values,
            ], $types);
        }
    }

    /**
     * Delete the installation row whose tables a finished purge has already dropped.
     *
     * The site is part of the criteria, so a purge planned on one site cannot delete another site's record
     * of the same definition. A delete that matches nothing is treated as a conflict rather than as an
     * idempotent no-op, because the purge only reaches this point having read the row it means to remove.
     *
     * @param   string  $definitionId    UUID of the definition whose installation is being purged.
     * @param   string  $siteIdentifier  Site the stored row must belong to for the delete to count.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When no row matched, meaning the installation disappeared while the
     *          purge was finalizing.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the delete.
     *
     * @since   2.0.0
     */
    public function remove(string $definitionId, string $siteIdentifier): void
    {
        $affected = $this->database->delete($this->tables->raw('business_schema_installations'), [
            'definition_id' => $definitionId,
            'site_identifier' => $siteIdentifier,
        ]);
        if ($affected !== 1) {
            throw new BusinessSchemaConflict('The schema installation disappeared during purge finalization.');
        }
    }

    /**
     * Read every installation an owner holds and lock those rows for the rest of the caller's transaction.
     *
     * The lock is the point of the method, which is why it refuses to run without an open transaction: a
     * `FOR UPDATE` taken in autocommit is released as the statement ends, and the lifecycle sweep decides
     * each row's next status from what it reads here before writing it back. Rows are ordered by site and
     * then definition so the sweep walks them the same way on every run.
     *
     * @param   string  $ownerIdentifier  `core`, an extension handle, or `vendor/package`, matched exactly.
     *
     * @return  list<SchemaInstallation>  That owner's installations across all sites, ordered by site then
     *          definition and locked until the caller commits or rolls back; empty when the owner installed
     *          nothing.
     *
     * @throws  BusinessSchemaConflict  When no transaction is open for the lock to be held in.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the locking read.
     * @throws  RuntimeException  When a stored column is absent, empty, or holds the wrong type.
     * @throws  \Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema  When a locked row no longer satisfies
     *          the installation invariants.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When a stored table's options
     *          cannot be canonically encoded.
     *
     * @since   2.0.0
     */
    public function ownedByForUpdate(string $ownerIdentifier): array
    {
        if (!$this->database->isTransactionActive()) {
            throw new BusinessSchemaConflict('Schema lifecycle reconciliation requires an active transaction.');
        }
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE owner_identifier = ? ORDER BY site_identifier, definition_id FOR UPDATE',
            $this->tables->quoted('business_schema_installations'),
        ), [$ownerIdentifier]);

        return array_map($this->map(...), $rows);
    }

    /**
     * Read a column that has to carry text, refusing an absent or empty one.
     *
     * @param   array<string, mixed>  $row  Driver row being mapped.
     * @param   string                $key  Column to read out of it.
     *
     * @return  string  The stored text, never an empty string.
     *
     * @throws  RuntimeException  When the column is absent, holds a non-string, or holds an empty string.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored schema installation property ' . $key . ' is invalid.');
        }
        return $value;
    }

    /**
     * Read a whole-number column, accepting the decimal text some drivers hand back for it.
     *
     * @param   array<string, mixed>  $row  Driver row being mapped.
     * @param   string                $key  Column to read out of it.
     *
     * @return  int  The stored number, converted from text when the driver did not type it.
     *
     * @throws  RuntimeException  When the column is absent, or holds neither an integer nor a run of digits.
     *
     * @since   2.0.0
     */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new RuntimeException('Stored schema installation property ' . $key . ' is invalid.');
    }

    /**
     * Turn the stored blueprint column into the string-keyed document the domain rebuilds from.
     *
     * Drivers disagree over whether a JSON column arrives decoded, so text is decoded here and an
     * already-decoded array is taken as it is. Anything that is a list once decoded is refused, and an
     * empty array counts as a list — a blueprint always carries its definition binding.
     *
     * @param   mixed  $value  Raw `blueprint` column value, decoded by the driver or still encoded.
     *
     * @return  array<string, mixed>  The decoded blueprint document.
     *
     * @throws  RuntimeException  When the column is not valid JSON, or decodes to anything other than a
     *          non-empty string-keyed object.
     *
     * @since   2.0.0
     */
    private function jsonObject(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Stored schema installation JSON is invalid.', 0, $exception);
            }
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('Stored schema installation blueprint is invalid.');
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Render a timestamp column as the text `SchemaInstallation::fromArray()` parses.
     *
     * A driver may return a date object or the raw column text depending on how the column was typed;
     * an object is formatted with microseconds and its offset, and text is passed through untouched.
     *
     * @param   mixed  $value  Raw `installed_at` or `updated_at` column value.
     *
     * @return  string  The timestamp as text the schema document layer can read back.
     *
     * @throws  RuntimeException  When the value is neither a date object nor a non-empty string.
     *
     * @since   2.0.0
     */
    private function date(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored schema installation timestamp is invalid.');
        }
        return $value;
    }
}
