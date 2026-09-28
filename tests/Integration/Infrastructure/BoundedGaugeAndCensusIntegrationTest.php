<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Infrastructure;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\App\Application\Retention\LedgerCensus;
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
