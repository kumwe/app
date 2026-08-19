<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Http\Handler\MetricsHandler;
use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\MetricRecorder;
use Kumwe\App\Infrastructure\Observability\MetricsAccessPolicy;
use Kumwe\App\Infrastructure\Observability\ObservabilityContract;
use Kumwe\App\Infrastructure\Observability\PrometheusExposition;
use Kumwe\App\Infrastructure\Observability\RedisMetricRecorder;
use Kumwe\App\Infrastructure\Observability\RuntimeMetricCollector;
use Kumwe\App\Infrastructure\Persistence\ReadinessStatus;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Infrastructure\Redis\RedisRuntime;
use Kumwe\App\Infrastructure\Time\SystemClock;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves the gauge queries and the exposition run identically on every supported engine.
 *
 * The queries deliberately avoid engine-specific date arithmetic, so the one thing worth checking
 * against a real database is exactly that: the same statement text answers on MariaDB, MySQL and
 * PostgreSQL, and a row seeded in the past produces a non-zero age rather than a driver error.
 */
#[CoversClass(RuntimeMetricCollector::class)]
#[CoversClass(RedisMetricRecorder::class)]
#[CoversClass(MetricsHandler::class)]
final class ObservabilityMetricsIntegrationTest extends TestCase
{
    private const TOKEN = 'metrics-scrape-token-derived-from-patterned-words-only';

    public function testTheGaugeQueriesAnswerOnThisEngineAndSeeSeededWork(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(TableNames::class, $tables);
        $jobId = $this->seedOverdueJob($database, $tables);

        try {
            $samples = $this->collector($database, $tables)->collect();
            $values = [];
            foreach ($samples as $sample) {
                $values[$sample->name] = $sample->value;
            }

            self::assertSame(0.0, $values['kumwe_metrics_collection_failed']);
            self::assertGreaterThanOrEqual(1.0, $values['kumwe_jobs_pending']);
            self::assertGreaterThanOrEqual(1.0, $values['kumwe_jobs_due']);
            self::assertGreaterThan(3_000.0, $values['kumwe_jobs_oldest_due_age_seconds']);
            self::assertSame(1.0, $values['kumwe_ready']);
            foreach (
                [
                    'kumwe_outbox_pending',
                    'kumwe_outbox_oldest_pending_age_seconds',
                    'kumwe_inbox_pending',
                    'kumwe_scheduler_lag_seconds',
                    'kumwe_process_work_overdue',
                    'kumwe_export_queue_depth',
                    'kumwe_worker_heartbeat_age_seconds',
                ] as $gauge
            ) {
                self::assertArrayHasKey($gauge, $values, sprintf('%s must answer on this engine.', $gauge));
            }
        } finally {
            $database->delete($tables->raw('jobs'), ['id' => $jobId], ['id' => Types::GUID]);
        }
    }

    public function testAnEmptyInstallationCollectsWithoutRaisingAndStaysCheap(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(TableNames::class, $tables);
        $collector = $this->collector($database, $tables);

        $started = microtime(true);
        $samples = $collector->collect();
        $elapsed = microtime(true) - $started;

        self::assertNotSame([], $samples);
        // A scrape is a fixed number of bounded aggregates, so it must stay far below any timeout an
        // operator would put on a scrape target. The bound is loose on purpose: it is a regression
        // guard against someone adding an unbounded query, not a benchmark.
        self::assertLessThan(2.0, $elapsed);
    }

    public function testCountersSurviveTheRequestThatWroteThemAndRenderAsCumulativeBuckets(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $redis = $container->get(RedisRuntime::class);
        self::assertInstanceOf(RedisRuntime::class, $redis);
        $catalog = self::catalog();
        $redis->forgetMetrics();

        try {
            $writer = new RedisMetricRecorder($redis, $catalog);
            $writer->increment(MetricCatalog::HTTP_REQUESTS, ['method' => 'GET', 'status' => '2xx']);
            $writer->increment(MetricCatalog::HTTP_REQUESTS, ['method' => 'GET', 'status' => '2xx']);
            $writer->increment(MetricCatalog::HTTP_REQUESTS, ['method' => 'POST', 'status' => '5xx']);
            $writer->observe(MetricCatalog::HTTP_DURATION, ['method' => 'GET'], 0.02);
            $writer->observe(MetricCatalog::HTTP_DURATION, ['method' => 'GET'], 7.5);

            // A second recorder stands in for the next request in a share-nothing runtime.
            $reader = new RedisMetricRecorder($redis, $catalog);
            $body = (new PrometheusExposition())->render($catalog, $reader->samples());

            self::assertStringContainsString('kumwe_http_requests_total{method="GET",status="2xx"} 2', $body);
            self::assertStringContainsString('kumwe_http_requests_total{method="POST",status="5xx"} 1', $body);
            self::assertStringContainsString('kumwe_http_request_duration_seconds_count{method="GET"} 2', $body);
            self::assertStringContainsString('kumwe_http_request_duration_seconds_sum{method="GET"} 7.52', $body);
            self::assertStringContainsString('duration_seconds_bucket{method="GET",le="0.025"} 1', $body);
            self::assertStringContainsString('duration_seconds_bucket{method="GET",le="10"} 2', $body);
            self::assertStringContainsString('duration_seconds_bucket{method="GET",le="+Inf"} 2', $body);
        } finally {
            $redis->forgetMetrics();
        }
    }

    public function testAnUnenumeratedLabelCannotGrowTheStoredKeyspace(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $redis = $container->get(RedisRuntime::class);
        self::assertInstanceOf(RedisRuntime::class, $redis);
        $redis->forgetMetrics();

        try {
            $recorder = new RedisMetricRecorder($redis, self::catalog());
            foreach (range(1, 40) as $index) {
                $recorder->increment(MetricCatalog::HTTP_REQUESTS, [
                    'method' => 'GET',
                    'status' => '2xx',
                    'record_id' => sprintf('patterned-example-record-%d', $index),
                ]);
            }

            self::assertCount(1, $redis->metrics());
        } finally {
            $redis->forgetMetrics();
        }
    }

    public function testTheGuardedEndpointServesAScrapeableDocumentEndToEnd(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(TableNames::class, $tables);
        $recorder = $container->get(MetricRecorder::class);
        self::assertInstanceOf(MetricRecorder::class, $recorder);
        $handler = new MetricsHandler(
            new MetricsAccessPolicy(true, false, self::TOKEN),
            self::catalog(),
            $recorder,
            $this->collector($database, $tables),
            new PrometheusExposition(),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/metrics')
            ->withHeader('Authorization', 'Bearer ' . self::TOKEN);

        $body = (string) $handler->handle($request)->getBody();

        self::assertStringContainsString('# TYPE kumwe_build_info gauge', $body);
        self::assertStringContainsString('# TYPE kumwe_jobs_pending gauge', $body);
        foreach (explode("\n", trim($body)) as $line) {
            self::assertMatchesRegularExpression(
                '/^(#|[a-z_]+(\{[^\n]*\})? -?[0-9+.eIfnaN]+$)/',
                $line,
                sprintf('Line "%s" is not exposition syntax.', $line),
            );
        }
    }

    private function collector(Connection $database, TableNames $tables): RuntimeMetricCollector
    {
        return new RuntimeMetricCollector(
            $database,
            $tables,
            new SystemClock(),
            new class implements ReadinessStatus {
                public function ready(): bool
                {
                    return true;
                }
            },
            '2.9.0-qualification',
            'http',
        );
    }

    private function seedOverdueJob(Connection $database, TableNames $tables): string
    {
        $id = Uuid::uuid7()->toString();
        $past = (new SystemClock())->now()->modify('-2 hours');
        $database->insert(
            $tables->raw('jobs'),
            [
                'id' => $id,
                'queue' => 'observability-metrics',
                'job_type' => 'observability.metrics.probe',
                'schema_version' => 1,
                'payload' => ['probe' => true],
                'priority' => 0,
                'status' => 'pending',
                'available_at' => $past,
                'attempts' => 0,
                'maximum_attempts' => 5,
                'created_at' => $past,
                'updated_at' => $past,
            ],
            [
                'id' => Types::GUID,
                'queue' => Types::STRING,
                'job_type' => Types::STRING,
                'schema_version' => Types::INTEGER,
                'payload' => Types::JSON,
                'priority' => Types::SMALLINT,
                'status' => Types::STRING,
                'available_at' => Types::DATETIME_IMMUTABLE,
                'attempts' => Types::SMALLINT,
                'maximum_attempts' => Types::SMALLINT,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ],
        );

        return $id;
    }

    private static function catalog(): MetricCatalog
    {
        return MetricCatalog::create(
            ObservabilityContract::load(dirname(__DIR__, 3)),
            '2.9.0-qualification',
            'http',
        );
    }
}
