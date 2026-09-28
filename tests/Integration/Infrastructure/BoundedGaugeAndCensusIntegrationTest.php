<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use InvalidArgumentException;
use Kumwe\App\Application\Retention\LedgerCensus;
use Kumwe\App\Application\Retention\LedgerCount;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\App\BusinessReporting\Infrastructure\DoctrineProjectionEventSequencer;
use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\RuntimeMetricCollector;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Infrastructure\Retention\DoctrineLedgerCensus;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\RecordingMetricRecorder;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the bounded scrape, the server gauges and the operator-only exact census on the configured engine.
 *
 * @since  2.0.0
 */
#[CoversClass(RuntimeMetricCollector::class)]
#[CoversClass(DoctrineLedgerCensus::class)]
#[CoversClass(LedgerCount::class)]
#[CoversClass(DoctrineProjectionEventSequencer::class)]
final class BoundedGaugeAndCensusIntegrationTest extends TestCase
{
    /**
     * The scrape publishes every bounded gauge, server saturation, and retention figures without failing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheScrapePublishesBoundedServerAndRetentionGauges(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $collector = $container->get(RuntimeMetricCollector::class);
        self::assertInstanceOf(RuntimeMetricCollector::class, $collector);
        $values = [];
        $stores = [];
        foreach ($collector->collect() as $sample) {
            $values[$sample->name] = $sample->value;
            if (($sample->labels['store'] ?? null) !== null) {
                $stores[$sample->labels['store']] = true;
            }
        }
        self::assertSame(0.0, $values['kumwe_metrics_collection_failed']);
        self::assertGreaterThan(0.0, $values['kumwe_database_connections_max']);
        self::assertGreaterThan(0.0, $values['kumwe_database_connections_in_use']);
        foreach (
            ['kumwe_database_replica_lag_seconds', 'kumwe_projection_staging_backlog', 'kumwe_metrics_capped_gauges',
                'kumwe_projection_staging_oldest_age_seconds', MetricCatalog::RETENTION_READINESS] as $gauge
        ) {
            self::assertArrayHasKey($gauge, $values);
        }
        self::assertCount(count(RetentionStore::cases()), $stores);
        self::assertLessThanOrEqual(RuntimeMetricCollector::PROBE_CAP, $values['kumwe_jobs_pending']);
    }

    /**
     * The census counts exactly under a server timeout, declares its cost class and refuses a bad timeout.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCensusCountsExactlyUnderAnExplicitTimeout(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $census = $container->get(LedgerCensus::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(LedgerCensus::class, $census);
        $count = $census->count(RetentionStore::JobHistory, 5_000);
        self::assertFalse($count->timedOut);
        self::assertSame('table_scan', $count->costClass);
        $exact = (int) $database->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $tables->quoted('jobs')));
        self::assertSame($exact, $count->rows);
        $this->expectException(InvalidArgumentException::class);
        $census->count(RetentionStore::JobHistory, 30_001);
    }

    /**
     * A census the engine cannot finish inside its timeout is cancelled and reported as timed out, not zero.
     *
     * A peer session holds the job table exclusively, so the exact count can only wait. The engine's own
     * statement timeout cancels it, and the census answers with no row count, the timeout it ran under and
     * the time it spent, instead of a number an operator could mistake for an empty ledger.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACensusBlockedPastItsTimeoutIsReportedAsTimedOutWithoutACount(): void
    {
        $environment = Environment::fromGlobals();
        $container = TestKernelFactory::create($environment);
        $peer = TestKernelFactory::create($environment)->get(Connection::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $census = $container->get(LedgerCensus::class);
        self::assertInstanceOf(Connection::class, $peer);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(LedgerCensus::class, $census);
        $mysql = $peer->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        if ($mysql) {
            // Bounds the wait should an engine ever ignore its statement timeout while blocked on a lock.
            $database->executeStatement('SET SESSION lock_wait_timeout = 10');
            $peer->executeStatement(sprintf('LOCK TABLES %s WRITE', $tables->quoted('jobs')));
        } else {
            $peer->beginTransaction();
            $peer->executeStatement(sprintf('LOCK TABLE %s IN ACCESS EXCLUSIVE MODE', $tables->quoted('jobs')));
        }
        try {
            $count = $census->count(RetentionStore::JobHistory, 250);
        } finally {
            if ($mysql) {
                $peer->executeStatement('UNLOCK TABLES');
            } else {
                $peer->rollBack();
            }
        }

        self::assertSame(RetentionStore::JobHistory, $count->store);
        self::assertTrue($count->timedOut);
        self::assertNull($count->rows);
        self::assertSame(250, $count->timeoutMilliseconds);
        self::assertGreaterThanOrEqual(200.0, $count->elapsedMilliseconds);
        self::assertSame('table_scan', $count->costClass);
        self::assertFalse($census->count(RetentionStore::JobHistory, 5_000)->timedOut, 'The lock is gone.');
    }

    /**
     * A sequencing pass that publishes nothing records nothing, so the counter is exact rather than per call.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEmptySequencingPassRecordsNoEvents(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $metrics = new RecordingMetricRecorder();
        $sequencer = new DoctrineProjectionEventSequencer(
            $database,
            $tables,
            new DoctrineTransactionManager($database),
            $metrics,
        );
        while ($sequencer->sequence(1_000) > 0) {
            continue;
        }
        $before = $metrics->total(MetricCatalog::SEQUENCED_EVENTS);
        self::assertSame(0, $sequencer->sequence());
        self::assertSame($before, $metrics->total(MetricCatalog::SEQUENCED_EVENTS));
    }
}
