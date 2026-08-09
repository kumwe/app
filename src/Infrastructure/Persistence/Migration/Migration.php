<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;

/**
 * One forward-only schema change, identified and fingerprinted so a site can prove which code it ran.
 *
 * `MigrationPlan` sorts implementations by `id()` and requires a database's ledger to be an exact
 * prefix of that order, so an implementation's ID and checksum are part of the released contract:
 * once a site has recorded them, neither may change. `MigrationRunner` applies the pending migrations
 * in order and records each ID and checksum pair through `MigrationRepository`. There is no down
 * path — recovery from a bad migration is forward, through a further migration.
 *
 * @since  2.0.0
 */
interface Migration
{
    /**
     * Returns the identifier this migration is ordered and recorded under.
     *
     * `MigrationPlan` requires a 14-digit timestamp, an underscore, then a lowercase snake_case name,
     * so plain string ordering of IDs is chronological ordering of migrations.
     *
     * @return  string  Ordering key, and the ledger row key the applied checksum is stored against.
     *
     * @since   2.0.0
     */
    public function id(): string;

    /**
     * Returns the fingerprint of the code this migration would run.
     *
     * The ledger stores this value when the migration is applied and `MigrationPlan` compares it on
     * every later boot, so it must stay stable once a release has shipped. The shipped migrations
     * derive it from their own file bytes, which makes an edit to an already-distributed migration
     * surface as checksum drift instead of a silent divergence between site and binary.
     *
     * @return  string  Lowercase 64-character SHA-256 digest; `MigrationPlan` rejects any other shape.
     *
     * @since   2.0.0
     */
    public function checksum(): string;

    /**
     * Applies this migration's schema and data change to the target database.
     *
     * The runner owns the transaction scope: on PostgreSQL this call and the ledger write share one
     * transaction, while on MySQL, where DDL commits implicitly, `NonTransactionalMigrationRecovery`
     * decides whether an interrupted attempt may be replayed. An implementation that is safe to run
     * again from any point declares so by implementing `RepeatableMigration`.
     *
     * @param   Connection  $database  Open connection to the schema being migrated.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void;
}
