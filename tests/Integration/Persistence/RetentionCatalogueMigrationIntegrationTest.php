<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Retention\RetentionCatalogue;
use Kumwe\App\Infrastructure\Persistence\Migration\ConstraintNameIsolationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\RetentionCatalogueMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Drives the retention catalogue migration against private tables on the configured engine.
 *
 * The shared installation ran this migration before any test, so it proves only that the ledger exists.
 * The transformation's own properties are proven here, under a prefix unique to each test: it creates the
 * run ledger keyed by store, adds the retention probe indexes only to the ledgers that are installed, seeds
 * one drain schedule per generic store exactly once, and a replay neither duplicates a schedule nor adds an
 * index twice. An installation without a scheduler table still gains its ledger and simply seeds nothing.
 *
 * @since  2.0.0
 */
#[CoversClass(RetentionCatalogueMigration::class)]
final class RetentionCatalogueMigrationIntegrationTest extends TestCase
{
    /**
     * A bare installation gains only the run ledger, keyed by store, and a replay changes nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testABareInstallationGainsOnlyTheRunLedger(): void
    {
        $database = $this->connection();
        $tables = $this->tables($database);
        $manager = $database->createSchemaManager();

        try {
            $migration = new RetentionCatalogueMigration($tables);
            $migration->up($database);
            $migration->up($database);

            $ledger = $manager->introspectTableByUnquotedName($tables->raw('retention_runs'));
            self::assertSame(
                ['store', 'ran_at', 'rows_drained', 'batches', 'elapsed_ms', 'final_batch', 'backlog_cleared',
                    'budget_exhausted'],
                array_map(static fn ($column): string => strtolower($column->getName()), array_values(
                    $ledger->getColumns(),
                )),
            );
            self::assertSame(['store'], array_map(
                static fn ($column): string => strtolower(trim($column->toString(), '"`')),
                $ledger->getPrimaryKeyConstraint()?->getColumnNames() ?? [],
            ));
            self::assertFalse($manager->tablesExist([$tables->raw('schedules')]), 'No scheduler is invented.');
        } finally {
            $this->drop($database, $tables, ['retention_runs']);
        }
    }

    /**
     * Installed ledgers gain their probe indexes and the scheduler gains six drain schedules, exactly once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInstalledLedgersGainTheirIndexesAndTheSchedulerItsDrainsExactlyOnce(): void
    {
        $database = $this->connection();
        $tables = $this->tables($database);
        $manager = $database->createSchemaManager();
        $jobs = new Table($tables->raw('jobs'));
        $jobs->addColumn('id', Types::GUID);
        $jobs->addColumn('status', Types::STRING, ['length' => 16]);
        $jobs->addColumn('available_at', Types::DATETIME_IMMUTABLE);
        $jobs->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $jobs->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $jobs->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $manager->createTable($jobs);
        $manager->createTable($this->schedulesTable($tables));

        try {
            $migration = new RetentionCatalogueMigration($tables);
            $migration->up($database);
            $migration->up($database);

            $installed = $manager->introspectTableByUnquotedName($tables->raw('jobs'));
            foreach (['idx_job_settled', 'idx_job_due', 'idx_job_ingest'] as $index) {
                self::assertTrue(
                    $installed->hasIndex(ConstraintNameIsolationMigration::isolatedName($tables->raw('jobs'), $index)),
                    $index,
                );
            }
            self::assertCount(4, $installed->getIndexes(), 'Three probe indexes beside the primary key, once.');
            $schedules = $database->fetchAllAssociative(sprintf(
                'SELECT id, job_type, payload FROM %s ORDER BY id',
                $tables->quoted('schedules'),
            ));
            self::assertCount(6, $schedules, 'One drain schedule per generic store, never duplicated.');
            $stores = [];
            foreach ($schedules as $schedule) {
                self::assertSame(RetentionCatalogue::DRAIN_JOB_TYPE, $schedule['job_type']);
                $payload = json_decode((string) $schedule['payload'], true);
                self::assertIsArray($payload);
                $stores[] = $payload['store'] ?? null;
            }
            self::assertSame([
                'outbox_source_events',
                'sequenced_journal',
                'inbox_receipts',
                'job_history',
                'process_history',
                'export_artifacts',
            ], $stores);
        } finally {
            $this->drop($database, $tables, ['retention_runs', 'schedules', 'jobs']);
        }
    }

    /**
     * Describe the scheduler columns the seeding writes.
     *
     * @param   TableNames  $tables  Private table-name compiler.
     *
     * @return  Table  Minimal private scheduler table.
     *
     * @since   2.0.0
     */
    private function schedulesTable(TableNames $tables): Table
    {
        $schedules = new Table($tables->raw('schedules'));
        $schedules->addColumn('id', Types::GUID);
        $lengths = ['name' => 160, 'cron_expression' => 120, 'timezone' => 80, 'queue' => 64, 'job_type' => 128];
        foreach ($lengths as $column => $length) {
            $schedules->addColumn($column, Types::STRING, ['length' => $length]);
        }
        $schedules->addColumn('job_schema_version', Types::INTEGER);
        $schedules->addColumn('payload', Types::JSON);
        $schedules->addColumn('priority', Types::SMALLINT);
        $schedules->addColumn('maximum_attempts', Types::SMALLINT);
        $schedules->addColumn('enabled', Types::BOOLEAN);
        $schedules->addColumn('next_run_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $schedules->addColumn('last_run_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $schedules->addColumn('version', Types::INTEGER);
        $schedules->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $schedules->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $schedules->addColumn('execution_scope', Types::STRING, ['length' => 16]);
        $schedules->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());

        return $schedules;
    }

    /**
     * Drop the private tables a test created.
     *
     * @param   Connection    $database  Integration connection.
     * @param   TableNames    $tables    Private table-name compiler.
     * @param   list<string>  $names     Logical tables to drop.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function drop(Connection $database, TableNames $tables, array $names): void
    {
        foreach ($names as $name) {
            $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $tables->quoted($name)));
        }
    }

    /**
     * Open the integration connection.
     *
     * @return  Connection  Connection to the configured engine.
     *
     * @since   2.0.0
     */
    private function connection(): Connection
    {
        $database = TestKernelFactory::create(Environment::fromGlobals())->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);

        return $database;
    }

    /**
     * Compile table names under a prefix no other test or installation can be using.
     *
     * @param   Connection  $database  Integration connection supplying identifier quoting.
     *
     * @return  TableNames  Compiler bound to a prefix unique to this test method.
     *
     * @since   2.0.0
     */
    private function tables(Connection $database): TableNames
    {
        return new TableNames($database, 'r' . substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 10) . '_');
    }
}
