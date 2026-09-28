<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\Migration\AsyncTraceContextMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Drives the asynchronous trace-context migration against private tables on the configured engine.
 *
 * The shared installation only proves the columns exist once. The transformation's own properties — that it
 * adds each missing optional trace column to the durable async tables that predate it, keeps a column an
 * installation already has, leaves a table the installation does not have uncreated, and changes nothing on
 * a replay — are proven by applying it to tables under a prefix unique to the test, so no run can disturb the
 * installation the suite shares.
 *
 * @since  2.0.0
 */
#[CoversClass(AsyncTraceContextMigration::class)]
final class AsyncTraceContextMigrationIntegrationTest extends TestCase
{
    /**
     * Legacy async tables gain their missing optional trace columns once, and absent tables stay absent.
     *
     * The job table already carries a correlation column, the outbox carries none and the inbox does not exist:
     * the job table gains only causation and trace columns, the outbox gains its trace column, stored rows keep
     * no trace, no inbox is invented, and a replay neither adds nor removes a column.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLegacyAsyncTablesGainTheirMissingTraceColumnsOnceAndAbsentTablesStayAbsent(): void
    {
        $database = $this->connection();
        $tables = $this->tables($database);
        $manager = $database->createSchemaManager();
        $jobs = new Table($tables->raw('jobs'));
        $jobs->addColumn('id', Types::GUID);
        $jobs->addColumn('correlation_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $jobs->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $outbox = new Table($tables->raw('integration_outbox'));
        $outbox->addColumn('id', Types::GUID);
        $outbox->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        $manager->createTable($jobs);
        $manager->createTable($outbox);
        $jobId = Uuid::uuid7()->toString();
        $eventId = Uuid::uuid7()->toString();
        $database->insert($tables->raw('jobs'), ['id' => $jobId, 'correlation_id' => 'request-before-trace']);
        $database->insert($tables->raw('integration_outbox'), ['id' => $eventId]);

        try {
            $migration = new AsyncTraceContextMigration($tables);
            $migration->up($database);
            $builtJobs = $manager->introspectTableByUnquotedName($tables->raw('jobs'));
            $builtOutbox = $manager->introspectTableByUnquotedName($tables->raw('integration_outbox'));
            self::assertSame(64, $builtJobs->getColumn('correlation_id')->getLength(), 'An existing column is kept.');
            foreach (['causation_id' => 191, 'trace_id' => 32] as $column => $length) {
                self::assertFalse($builtJobs->getColumn($column)->getNotnull(), $column);
                self::assertSame($length, $builtJobs->getColumn($column)->getLength(), $column);
            }
            self::assertFalse($builtOutbox->getColumn('trace_id')->getNotnull());
            self::assertSame(32, $builtOutbox->getColumn('trace_id')->getLength());
            self::assertSame(
                ['correlation_id' => 'request-before-trace', 'causation_id' => null, 'trace_id' => null],
                $database->fetchAssociative(sprintf(
                    'SELECT correlation_id, causation_id, trace_id FROM %s WHERE id = ?',
                    $tables->quoted('jobs'),
                ), [$jobId]),
                'A job queued before the migration keeps its correlation and carries no trace.',
            );
            self::assertNull($database->fetchOne(sprintf(
                'SELECT trace_id FROM %s WHERE id = ?',
                $tables->quoted('integration_outbox'),
            ), [$eventId]));
            self::assertFalse(
                $manager->tablesExist([$tables->raw('integration_inbox')]),
                'A table the installation does not have is not invented.',
            );

            $migration->up($database);
            self::assertSame(
                array_keys($builtJobs->getColumns()),
                array_keys($manager->introspectTableByUnquotedName($tables->raw('jobs'))->getColumns()),
                'A replay neither adds nor removes a job column.',
            );
            self::assertSame(
                array_keys($builtOutbox->getColumns()),
                array_keys($manager->introspectTableByUnquotedName($tables->raw('integration_outbox'))->getColumns()),
                'A replay neither adds nor removes an outbox column.',
            );
        } finally {
            foreach (['jobs', 'integration_outbox', 'integration_inbox'] as $name) {
                $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $tables->quoted($name)));
            }
        }
    }

    /**
     * The migration names itself and checksums its own bytes, so an edit after release is detectable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheMigrationIdentityAndChecksumAreDerivedFromItsOwnBytes(): void
    {
        $migration = new AsyncTraceContextMigration($this->tables($this->connection()));
        $file = (new \ReflectionClass(AsyncTraceContextMigration::class))->getFileName();
        self::assertIsString($file);

        self::assertSame('20260924130000_async_trace_context', $migration->id());
        self::assertSame(
            hash('sha256', AsyncTraceContextMigration::ID . ':' . hash_file('sha256', $file)),
            $migration->checksum(),
        );
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
        return new TableNames($database, 't' . bin2hex(random_bytes(5)) . '_');
    }
}
