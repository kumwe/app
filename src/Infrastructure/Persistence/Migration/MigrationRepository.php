<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

/**
 * Read and write access to the schema-migration ledger, the record of what a database has run.
 *
 * The ledger is the only durable statement of a database's schema version. `MigrationRunner` reads it
 * to work out what is still pending and appends a row for each migration it completes, and
 * `ReadinessProbe` reads the same map to keep an instance out of service until its schema matches the
 * plan its code ships. `DoctrineMigrationRepository` keeps the ledger in the `schema_migrations`
 * table.
 *
 * @since  2.0.0
 */
interface MigrationRepository
{
    /**
     * Creates the ledger table when it is not present, so a fresh database can record its first run.
     *
     * `MigrationRunner` calls this before reading the ledger or applying anything, so an
     * implementation must do nothing when the table is already present. `ReadinessProbe` deliberately
     * does not call it: a database with no ledger is reported unready rather than quietly given one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function ensureLedger(): void;

    /**
     * Returns every migration this database has recorded as applied.
     *
     * Each checksum is the one captured when that migration ran, not the current code's checksum —
     * comparing the two is how `MigrationPlan` detects that a released migration was edited. An empty
     * map means the database has never been migrated.
     *
     * @return  array<string, string>  Map of migration ID to checksum.
     *
     * @since   2.0.0
     */
    public function applied(): array;

    /**
     * Appends the ledger row that marks one migration as applied.
     *
     * @param   string  $id                     ID of the migration that just completed.
     * @param   string  $checksum               Fingerprint of the code that ran, kept for drift detection.
     * @param   int     $executionMilliseconds  Wall-clock duration of the migration, for operator diagnostics.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(string $id, string $checksum, int $executionMilliseconds): void;
}
