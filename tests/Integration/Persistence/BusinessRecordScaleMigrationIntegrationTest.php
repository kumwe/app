<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Persistence;

use Doctrine\DBAL\DriverManager;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessIntegrationSdkMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessRecordScaleMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises additive retention upgrades against operator customizations and repeated migration calls.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordScaleMigration::class)]
final class BusinessRecordScaleMigrationIntegrationTest extends TestCase
{
    /**
     * Only shipped cadence and budgets are upgraded, and a disabled schedule is never enabled.
     *
     * @param  string  $cron      Existing operator cadence.
     * @param  string  $payload   Existing serialized job budget.
     * @param  bool    $enabled   Existing operator activation choice.
     * @param  bool    $upgraded  Whether the untouched shipped budget is eligible.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('schedules')]
    public function testUpgradePreservesOperatorChoicesAndIsRepeatable(
        string $cron,
        string $payload,
        bool $enabled,
        bool $upgraded,
    ): void {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'retention_');
        try {
            (new BusinessIntegrationSdkMigration($tables))->up($database);
            $database->executeStatement(sprintf(
                'CREATE TABLE %s (id VARCHAR(36) PRIMARY KEY, expires_at DATETIME NOT NULL)',
                $tables->quoted('business_command_idempotency'),
            ));
            $database->executeStatement(sprintf(
                'CREATE TABLE %s (id VARCHAR(36) PRIMARY KEY, job_type VARCHAR(191) NOT NULL, '
                . 'cron_expression VARCHAR(96) NOT NULL, payload CLOB NOT NULL, enabled BOOLEAN NOT NULL, '
                . 'next_run_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INTEGER NOT NULL)',
                $tables->quoted('schedules'),
            ));
            $database->insert($tables->raw('schedules'), [
                'id' => '00000000-0000-7000-8000-000000000803',
                'job_type' => 'business.record.idempotency.purge',
                'cron_expression' => $cron,
                'payload' => $payload,
                'enabled' => (int) $enabled,
                'next_run_at' => '2026-01-01 00:43:00',
                'updated_at' => '2026-01-01 00:00:00',
                'version' => 7,
            ]);
            $migration = new BusinessRecordScaleMigration($tables);
            $migration->up($database);
            $row = $database->fetchAssociative(sprintf('SELECT * FROM %s', $tables->quoted('schedules')));
            self::assertIsArray($row);
            self::assertSame($enabled, (bool) $row['enabled']);
            self::assertSame($upgraded ? '* * * * *' : $cron, $row['cron_expression']);
            self::assertSame($upgraded ? 8 : 7, (int) $row['version']);
            self::assertIsString($row['payload']);
            self::assertEquals(
                $upgraded ? ['batch_size' => 1000, 'maximum_batches' => 100] : json_decode($payload, true),
                json_decode($row['payload'], true),
            );
            $schema = $database->createSchemaManager()->introspectSchema();
            self::assertTrue($schema->hasTable($tables->raw('business_projection_event_staging')));
            $orders = [
                'business_command_idempotency' => ['expires_at', 'id'],
                'integration_outbox' => ['retained_until', 'event_id'],
            ];
            foreach ($orders as $table => $columns) {
                $matching = 0;
                foreach ($schema->getTable($tables->raw($table))->getIndexes() as $index) {
                    if ($index->getColumns() === $columns) {
                        ++$matching;
                    }
                }
                self::assertSame(1, $matching, 'The expiry scan has one covering order index.');
            }
            $migration->up($database);
            self::assertSame($row, $database->fetchAssociative(sprintf(
                'SELECT * FROM %s',
                $tables->quoted('schedules'),
            )), 'A repeated upgrade cannot move the schedule or change its version.');
        } finally {
            $database->close();
        }
    }

    /**
     * An eligible schedule whose stored version is not a positive integer is refused, not re-versioned.
     *
     * The upgrade rewrites the shipped schedule under a compare-and-set on its version, and increments it.
     * A version it cannot read as a positive integer cannot be incremented or matched honestly, so the
     * migration stops and leaves the schedule exactly as the operator's installation holds it. The migration
     * also names itself and checksums its own bytes, so an edit after release is detectable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEligibleScheduleWithAnUnreadableVersionIsRefusedAndLeftUntouched(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'retention_');
        try {
            (new BusinessIntegrationSdkMigration($tables))->up($database);
            $database->executeStatement(sprintf(
                'CREATE TABLE %s (id VARCHAR(36) PRIMARY KEY, expires_at DATETIME NOT NULL)',
                $tables->quoted('business_command_idempotency'),
            ));
            $database->executeStatement(sprintf(
                'CREATE TABLE %s (id VARCHAR(36) PRIMARY KEY, job_type VARCHAR(191) NOT NULL, '
                . 'cron_expression VARCHAR(96) NOT NULL, payload CLOB NOT NULL, enabled BOOLEAN NOT NULL, '
                . 'next_run_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INTEGER NOT NULL)',
                $tables->quoted('schedules'),
            ));
            $database->insert($tables->raw('schedules'), [
                'id' => '00000000-0000-7000-8000-000000000803',
                'job_type' => 'business.record.idempotency.purge',
                'cron_expression' => '43 * * * *',
                'payload' => '{"batch_size":500,"maximum_batches":10}',
                'enabled' => 1,
                'next_run_at' => '2026-01-01 00:43:00',
                'updated_at' => '2026-01-01 00:00:00',
                'version' => 'seven',
            ]);
            $migration = new BusinessRecordScaleMigration($tables);
            self::assertSame(
                hash('sha256', $migration->id() . ':' . hash_file(
                    'sha256',
                    (string) (new \ReflectionClass(BusinessRecordScaleMigration::class))->getFileName(),
                )),
                $migration->checksum(),
            );

            try {
                $migration->up($database);
                self::fail('A schedule version that is not a positive integer must be refused.');
            } catch (\RuntimeException $refusal) {
                self::assertSame('The retention schedule version is invalid.', $refusal->getMessage());
            }
            $row = $database->fetchAssociative(sprintf('SELECT * FROM %s', $tables->quoted('schedules')));
            self::assertIsArray($row);
            self::assertSame('43 * * * *', $row['cron_expression']);
            self::assertSame('seven', $row['version']);
        } finally {
            $database->close();
        }
    }

    /**
     * Cover untouched defaults, a disabled default, reordered JSON, and independent operator overrides.
     *
     * @return  iterable<string, array{string, string, bool, bool}>  Existing configuration and eligibility.
     *
     * @since   2.0.0
     */
    public static function schedules(): iterable
    {
        yield 'shipped' => ['43 * * * *', '{"batch_size":500,"maximum_batches":10}', true, true];
        yield 'disabled' => ['43 * * * *', '{"batch_size":500,"maximum_batches":10}', false, true];
        yield 'reordered JSON' => ['43 * * * *', '{"maximum_batches":10,"batch_size":500}', true, true];
        yield 'custom cadence' => ['*/5 * * * *', '{"batch_size":500,"maximum_batches":10}', true, false];
        yield 'custom budget' => ['43 * * * *', '{"batch_size":250,"maximum_batches":20}', true, false];
    }
}
