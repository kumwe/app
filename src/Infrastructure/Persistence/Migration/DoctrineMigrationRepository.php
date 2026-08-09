<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Migration ledger kept in the `schema_migrations` table and reached through Doctrine DBAL.
 *
 * This is the shipped `MigrationRepository`: one row per applied migration, keyed by the migration ID
 * and carrying the checksum of the code that ran, when it ran, and how long it took. Rows are only
 * ever appended — nothing here updates or deletes one — because `MigrationPlan` treats the ledger as
 * the authoritative statement of a database's schema version and compares each stored checksum
 * against the migration this binary ships. Concurrency is not this class's problem: `MigrationRunner`
 * holds `MigrationLock` around every write, and the `version` primary key is the last line of
 * defence against a migration being recorded twice.
 *
 * @since  2.0.0
 */
final readonly class DoctrineMigrationRepository implements MigrationRepository
{
    /**
     * Bind the ledger to the database whose schema version it records.
     *
     * @param  Connection  $database  Connection the ledger table is created in, read from, and
     *         appended to.
     * @param  TableNames  $tables    Resolver for the prefixed physical name of `schema_migrations`.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Create the `schema_migrations` table when the database does not already have one.
     *
     * Returns without touching anything once the table exists, so `MigrationRunner` can call it at
     * the head of every pass. The version column is the primary key, and the checksum column is fixed
     * at 64 characters because a SHA-256 digest in hexadecimal is exactly that wide.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function ensureLedger(): void
    {
        $schema = $this->database->createSchemaManager();
        $tableName = $this->tables->raw('schema_migrations');

        if ($schema->tablesExist([$tableName])) {
            return;
        }

        $table = new \Doctrine\DBAL\Schema\Table($tableName);
        $table->addColumn('version', Types::STRING, ['length' => 191]);
        $table->addColumn('checksum', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('executed_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('execution_ms', Types::INTEGER, ['unsigned' => true]);
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('version')->create(),
        );
        $schema->createTable($table);
    }

    /**
     * Read every migration this database has recorded as applied.
     *
     * Rows are selected in `version` order, which for the ID format the plan enforces is also
     * chronological order, so the caller can compare the result against the plan position by
     * position. Each checksum is the one captured when that migration ran, not the current code's, so
     * a difference is exactly the drift `MigrationPlan` refuses to migrate past. An empty map means
     * the ledger table exists but nothing has been applied to this database yet.
     *
     * @return  array<string, string>  Migration ID mapped to the checksum recorded for it.
     *
     * @throws  RuntimeException  When a ledger row holds a version or checksum that is not a string.
     *
     * @since   2.0.0
     */
    public function applied(): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT version, checksum FROM %s ORDER BY version',
            $this->tables->quoted('schema_migrations'),
        ));
        $applied = [];

        foreach ($rows as $row) {
            $version = $row['version'] ?? null;
            $checksum = $row['checksum'] ?? null;

            if (!is_string($version) || !is_string($checksum)) {
                throw new RuntimeException('The migration ledger contains invalid data.');
            }

            $applied[$version] = $checksum;
        }

        return $applied;
    }

    /**
     * Append the ledger row that marks one migration as applied.
     *
     * The insert is the commit point of a migration: on PostgreSQL it shares the transaction the
     * migration ran in, so an attempt that fails leaves no row behind at all. The execution timestamp
     * is taken here rather than passed in, and always in UTC, so ledgers stay comparable across
     * replicas in different timezones.
     *
     * @param   string  $id                     ID of the migration that just completed.
     * @param   string  $checksum               Fingerprint of the code that ran, kept so a later boot
     *          can detect that a released migration was edited.
     * @param   int     $executionMilliseconds  Wall-clock duration of the migration, recorded for
     *          operator diagnostics only.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(string $id, string $checksum, int $executionMilliseconds): void
    {
        $this->database->insert($this->tables->raw('schema_migrations'), [
            'version' => $id,
            'checksum' => $checksum,
            'executed_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'execution_ms' => $executionMilliseconds,
        ], [
            'version' => Types::STRING,
            'checksum' => Types::STRING,
            'executed_at' => Types::DATETIME_IMMUTABLE,
            'execution_ms' => Types::INTEGER,
        ]);
    }
}
