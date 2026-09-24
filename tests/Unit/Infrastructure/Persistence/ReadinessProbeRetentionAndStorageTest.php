<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Kumwe\App\Application\Retention\RetentionObservation;
use Kumwe\App\Application\Retention\RetentionObserver;
use Kumwe\App\Application\Retention\RetentionReadiness;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\App\Infrastructure\Persistence\FilesystemStorageReserve;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationPlan;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationRepository;
use Kumwe\App\Infrastructure\Persistence\Migration\NonTransactionalMigrationRecovery;
use Kumwe\App\Infrastructure\Persistence\ReadinessProbe;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Pins how retention and the storage reserve decide readiness under the baseline and enterprise profiles.
 *
 * A baseline installation keeps serving through an unconfigured retention setting or a volume below its
 * free-space reserve, and says so in the log; an enterprise installation drains instead. A retention store
 * at its declared capacity fails readiness under either profile, because no profile can serve writes it
 * cannot store.
 *
 * @since  2.0.0
 */
#[CoversClass(ReadinessProbe::class)]
final class ReadinessProbeRetentionAndStorageTest extends TestCase
{
    /**
     * An unconfigured retention store warns under the baseline profile and drains an enterprise one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnconfiguredStoreWarnsOnBaselineAndDrainsEnterprise(): void
    {
        $observation = self::observation(configured: false, backlog: 0);
        $baselineLog = self::log();
        $enterpriseLog = self::log();

        self::assertTrue(self::probe($baselineLog, [$observation], false)->ready());
        self::assertFalse(self::probe($enterpriseLog, [$observation], true)->ready());
        self::assertSame([['warning', 'Retention readiness warning.']], $baselineLog->entries);
        self::assertSame([['error', 'Retention readiness failed.']], $enterpriseLog->entries);
    }

    /**
     * A store whose backlog has reached its capacity fails readiness even on the baseline profile.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStoreAtCapacityFailsReadinessOnEveryProfile(): void
    {
        $log = self::log();

        self::assertFalse(self::probe($log, [self::observation(configured: true, backlog: 10)], false)->ready());
        self::assertSame([['error', 'Retention readiness failed.']], $log->entries);
    }

    /**
     * A volume below its free-space reserve warns on the baseline profile and drains an enterprise one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAVolumeBelowItsReserveWarnsOnBaselineAndDrainsEnterprise(): void
    {
        $short = new FilesystemStorageReserve(sys_get_temp_dir(), 0.99999);
        $ample = new FilesystemStorageReserve(sys_get_temp_dir(), 0.00001);
        $baselineLog = self::log();
        $enterpriseLog = self::log();

        self::assertTrue(self::probe($baselineLog, [], false, $short)->ready());
        self::assertFalse(self::probe($enterpriseLog, [], true, $short)->ready());
        self::assertTrue(self::probe(self::log(), [], true, $ample)->ready());
        self::assertSame(
            [['warning', 'Database volume is below its free-space reserve.']],
            $baselineLog->entries,
        );
        self::assertSame(
            [['warning', 'Database volume is below its free-space reserve.']],
            $enterpriseLog->entries,
        );
    }

    /**
     * Build a probe whose database, ledger and recovery checks all pass.
     *
     * @param   AbstractLogger                    $log           Logger capturing the verdict.
     * @param   list<RetentionObservation>        $observations  Retention observations to assess.
     * @param   bool                              $enterprise    Whether the enterprise profile applies.
     * @param   ?FilesystemStorageReserve         $storage       Storage reserve, or none.
     *
     * @return  ReadinessProbe  Probe under test.
     *
     * @since   2.0.0
     */
    private static function probe(
        AbstractLogger $log,
        array $observations,
        bool $enterprise,
        ?FilesystemStorageReserve $storage = null,
    ): ReadinessProbe {
        $schema = self::createStub(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturn(true);
        $database = self::createStub(Connection::class);
        $database->method('createSchemaManager')->willReturn($schema);
        $database->method('quoteSingleIdentifier')->willReturn('"kumwe_schema_migrations"');
        $database->method('fetchOne')->willReturn(1);
        $repository = self::createStub(MigrationRepository::class);
        $repository->method('applied')->willReturn([]);
        $retention = self::createStub(RetentionObserver::class);
        $retention->method('observeAll')->willReturn($observations);

        return new ReadinessProbe(
            $database,
            $log,
            new TableNames($database, 'kumwe_'),
            $repository,
            new MigrationPlan([]),
            self::createStub(NonTransactionalMigrationRecovery::class),
            retention: $retention,
            retentionReadiness: new RetentionReadiness(),
            enterprise: $enterprise,
            storage: $storage,
        );
    }

    /**
     * Build one job-history observation with a capacity of ten rows and no drain slope.
     *
     * @param   bool  $configured  Whether every required retention setting is present.
     * @param   int   $backlog     Rows eligible for removal.
     *
     * @return  RetentionObservation  Observation.
     *
     * @since   2.0.0
     */
    private static function observation(bool $configured, int $backlog): RetentionObservation
    {
        return new RetentionObservation(
            RetentionStore::JobHistory,
            new DateTimeImmutable('2026-09-24T12:00:00+00:00'),
            null,
            null,
            null,
            $backlog,
            false,
            null,
            null,
            10,
            $configured,
            $configured ? [] : ['schedule:system.retention.drain:job_history'],
            null,
        );
    }

    /**
     * Build a logger that keeps each entry's level and message.
     *
     * @return  AbstractLogger  Capturing logger exposing its `entries`.
     *
     * @since   2.0.0
     */
    private static function log(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /**
             * Captured entries as level and message.
             *
             * @var    list<array{string, string}>
             * @since  2.0.0
             */
            public array $entries = [];

            /**
             * Keep the level and message of one entry.
             *
             * @param   mixed               $level    Log level.
             * @param   string|Stringable   $message  Message.
             * @param   array<mixed>        $context  Ignored context.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->entries[] = [(string) $level, (string) $message];
            }
        };
    }
}
