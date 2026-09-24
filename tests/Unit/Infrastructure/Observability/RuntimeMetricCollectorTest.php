<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Observability;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\App\Application\Readiness\ReadinessStatus;
use Kumwe\App\Application\Retention\RetentionObserver;
use Kumwe\App\Infrastructure\Observability\MetricSample;
use Kumwe\App\Infrastructure\Observability\RuntimeMetricCollector;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Pins that a scrape degrades to explicit gauges instead of failing when its sources refuse to answer.
 *
 * A metrics endpoint that throws is indistinguishable from a dead process to the scraper, so every failing
 * source must still yield a complete document: an unreachable readiness probe reads as not ready, a durable
 * probe or retention observation that fails raises the collection-failed gauge, and an engine that does not
 * understand the server status statements reports zero saturation rather than aborting the scrape. The
 * engine here is an in-memory database that has the durable tables but none of the MySQL-family or
 * PostgreSQL status statements, which is exactly the refusal the server gauges must absorb.
 *
 * @since  2.0.0
 */
#[CoversClass(RuntimeMetricCollector::class)]
final class RuntimeMetricCollectorTest extends TestCase
{
    /**
     * Instant every scrape in this test is taken at.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string NOW = '2026-09-24T12:00:00+00:00';

    /**
     * Durable gauges answer from the tables, while refused server statements read as zero saturation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusedServerStatusStatementsReadAsZeroWithoutFailingTheScrape(): void
    {
        $database = self::database(true);
        $database->insert('kumwe_jobs', [
            'status' => 'pending',
            'available_at' => '2026-09-24 11:59:00',
            'lease_expires_at' => null,
        ]);
        $database->insert('kumwe_worker_heartbeats', ['heartbeat_at' => 'not a timestamp']);

        $samples = self::values(self::collector($database, self::readiness(true))->collect());

        self::assertSame(1.0, $samples['kumwe_ready']);
        self::assertSame(0.0, $samples['kumwe_metrics_collection_failed']);
        self::assertSame(1.0, $samples['kumwe_jobs_pending']);
        self::assertSame(1.0, $samples['kumwe_jobs_due']);
        self::assertSame(60.0, $samples['kumwe_jobs_oldest_due_age_seconds']);
        self::assertSame(0.0, $samples['kumwe_worker_heartbeat_age_seconds'], 'An unparsable beat has no age.');
        self::assertSame(0.0, $samples['kumwe_database_connections_in_use']);
        self::assertSame(0.0, $samples['kumwe_database_connections_max']);
        self::assertSame(0.0, $samples['kumwe_database_replica_lag_seconds']);
    }

    /**
     * Unreachable readiness, missing durable tables and a failing retention probe still yield a document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFailingSourcesRaiseTheCollectionFailedGaugeInsteadOfThrowing(): void
    {
        $retention = self::createStub(RetentionObserver::class);
        $retention->method('observeAll')->willThrowException(new RuntimeException('retention probe failed'));

        $samples = self::values(self::collector(
            self::database(false),
            self::readiness(null),
            $retention,
        )->collect());

        self::assertSame(1.0, $samples['kumwe_build_info']);
        self::assertSame(0.0, $samples['kumwe_ready'], 'A readiness probe that throws reads as not ready.');
        self::assertSame(1.0, $samples['kumwe_metrics_collection_failed']);
        self::assertArrayNotHasKey('kumwe_jobs_pending', $samples, 'No durable gauge is invented.');
        self::assertArrayHasKey('kumwe_metrics_scrape_duration_seconds', $samples);
    }

    /**
     * Build the collector under test at a fixed instant.
     *
     * @param   Connection          $database   Engine the durable probes read.
     * @param   ReadinessStatus     $readiness  Readiness probe.
     * @param   ?RetentionObserver  $retention  Retention observer, or none.
     *
     * @return  RuntimeMetricCollector  Collector under test.
     *
     * @since   2.0.0
     */
    private static function collector(
        Connection $database,
        ReadinessStatus $readiness,
        ?RetentionObserver $retention = null,
    ): RuntimeMetricCollector {
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));

        return new RuntimeMetricCollector(
            $database,
            new TableNames($database, 'kumwe_'),
            $clock,
            $readiness,
            '2.0.0-test',
            'test',
            $retention,
        );
    }

    /**
     * Build a readiness probe that answers, or throws when given null.
     *
     * @param   ?bool  $ready  Readiness answer, or null to throw.
     *
     * @return  ReadinessStatus  Probe.
     *
     * @since   2.0.0
     */
    private static function readiness(?bool $ready): ReadinessStatus
    {
        $readiness = self::createStub(ReadinessStatus::class);
        if ($ready === null) {
            $readiness->method('ready')->willThrowException(new RuntimeException('readiness unavailable'));
        } else {
            $readiness->method('ready')->willReturn($ready);
        }

        return $readiness;
    }

    /**
     * Open an in-memory engine, with or without the durable tables the gauges read.
     *
     * @param   bool  $durable  True to create the durable tables.
     *
     * @return  Connection  Engine without any server status statements.
     *
     * @since   2.0.0
     */
    private static function database(bool $durable): Connection
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        if (!$durable) {
            return $database;
        }
        $tables = [
            'integration_outbox' => 'status TEXT, created_at TEXT',
            'integration_inbox' => 'status TEXT, first_received_at TEXT',
            'jobs' => 'status TEXT, available_at TEXT, lease_expires_at TEXT',
            'failed_jobs' => 'id TEXT',
            'worker_heartbeats' => 'heartbeat_at TEXT',
            'schedules' => 'enabled INTEGER, next_run_at TEXT',
            'business_process_work' => 'status TEXT, due_at TEXT',
            'business_report_export_artifacts' => 'status TEXT, expires_at TEXT',
            'business_projection_event_staging' => 'recorded_at TEXT',
        ];
        foreach ($tables as $name => $columns) {
            $database->executeStatement(sprintf('CREATE TABLE kumwe_%s (%s)', $name, $columns));
        }

        return $database;
    }

    /**
     * Index unlabelled samples by name.
     *
     * @param   list<MetricSample>  $samples  Scrape result.
     *
     * @return  array<string, float>  Value by sample name.
     *
     * @since   2.0.0
     */
    private static function values(array $samples): array
    {
        $values = [];
        foreach ($samples as $sample) {
            if ($sample->labels === [] || $sample->name === 'kumwe_build_info') {
                $values[$sample->name] = $sample->value;
            }
        }

        return $values;
    }
}
