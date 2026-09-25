<?php

/**
 * Benchmark every durable gauge query and the retention drain at representative size (P5-E, P5-F).
 *
 * Usage: php tools/perf-gauges.php [--rows=200000] [--repeats=5] [--drain-rows=50000]
 *
 * Seeds the requested number of synthetic rows into `jobs`, `integration_outbox` and
 * `business_command_idempotency` on the configured disposable test database, then times, with the
 * engine's plan, each gauge statement the scrape used to run (exact COUNT, MIN, MAX) beside the bounded
 * probe that replaces it. It then seeds an expired idempotency backlog and drains it through the
 * production `RetentionDrain` with the catalogue's declared budget, reporting the run rate against the
 * 1,852 rows a second a half-duty-cycle drain needs to sustain 926 (twice the enterprise peak).
 * Every seeded row is removed afterwards. Requires APP_ENV=testing. Writes build/perf/gauges-<engine>.json.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\Application\Retention\RetentionCatalogue;
use Kumwe\App\Application\Retention\RetentionDrain;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Ramsey\Uuid\Uuid;

if (getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "The gauge benchmark requires APP_ENV=testing and a disposable test database.\n");
    exit(2);
}
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$options = ['rows' => 200_000, 'repeats' => 5, 'drain-rows' => 50_000];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(rows|repeats|drain-rows)=(\d+)$/D', $argument, $match) !== 1) {
        fwrite(STDERR, "Usage: php tools/perf-gauges.php [--rows=N] [--repeats=N] [--drain-rows=N]\n");
        exit(2);
    }
    $options[$match[1]] = (int) $match[2];
}
$container = TestKernelFactory::create(Environment::fromGlobals());
$database = $container->get(Connection::class);
$tables = $container->get(TableNames::class);
$drain = $container->get(RetentionDrain::class);
if (!$database instanceof Connection || !$tables instanceof TableNames || !$drain instanceof RetentionDrain) {
    throw new RuntimeException('The benchmark services are unavailable.');
}
$postgres = $database->getDatabasePlatform() instanceof PostgreSQLPlatform;
$engine = $postgres ? 'pgsql' : 'mariadb';
$nonce = bin2hex(random_bytes(4));
$queue = 'perf.gauge' . $nonce;

/**
 * Insert rows in multi-row statements.
 *
 * @param   Connection            $database  Session.
 * @param   string                $table     Quoted table.
 * @param   list<string>          $columns   Column names.
 * @param   int                   $count     Rows to insert.
 * @param   callable(int): list<mixed>  $row  Values for row N.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function perfSeed(Connection $database, string $table, array $columns, int $count, callable $row): void
{
    $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    for ($offset = 0; $offset < $count; $offset += 500) {
        $values = [];
        $groups = [];
        for ($index = $offset; $index < min($count, $offset + 500); $index++) {
            array_push($values, ...$row($index));
            $groups[] = $placeholders;
        }
        $database->executeStatement(sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $table,
            implode(', ', $columns),
            implode(', ', $groups),
        ), $values);
    }
}

/**
 * Time a statement over repeats and capture its plan.
 *
 * @param   Connection  $database  Session.
 * @param   string      $sql       Statement without parameters.
 * @param   int         $repeats   Timed executions after one warm-up.
 * @param   bool        $postgres  Whether to use the PostgreSQL plan syntax.
 *
 * @return  array<string, mixed>  Median and maximum milliseconds and the compact plan.
 *
 * @since   2.0.0
 */
function perfTime(Connection $database, string $sql, int $repeats, bool $postgres): array
{
    $database->fetchOne($sql);
    $timings = [];
    for ($index = 0; $index < $repeats; $index++) {
        $started = hrtime(true);
        $database->fetchOne($sql);
        $timings[] = (hrtime(true) - $started) / 1_000_000;
    }
    sort($timings);
    $plan = $postgres
        ? implode(' | ', array_map(
            static fn (array $row): string => trim((string) reset($row)),
            $database->fetchAllAssociative('EXPLAIN ' . $sql),
        ))
        : implode(' | ', array_map(
            static fn (array $row): string => sprintf(
                '%s:%s:%s:rows=%s:%s',
                $row['table'] ?? '',
                $row['type'] ?? '',
                $row['key'] ?? '',
                $row['rows'] ?? '',
                $row['Extra'] ?? '',
            ),
            $database->fetchAllAssociative('EXPLAIN ' . $sql),
        ));

    return [
        'median_ms' => round($timings[intdiv(count($timings), 2)], 3),
        'max_ms' => round($timings[count($timings) - 1], 3),
        'plan' => $plan,
    ];
}

$jobs = $tables->quoted('jobs');
$outbox = $tables->quoted('integration_outbox');
$idempotency = $tables->quoted('business_command_idempotency');
$now = new DateTimeImmutable();
$stamp = static fn (string $modify): string => $now->modify($modify)->format('Y-m-d H:i:s');
$report = [
    'tool' => 'kumwe-gauge-benchmark',
    'engine' => $engine,
    'database_version' => (string) $database->fetchOne('SELECT VERSION()'),
    'seeded_rows_per_table' => $options['rows'],
    'repeats' => $options['repeats'],
    'measured_at' => gmdate('c'),
    'gauges' => [],
    'retention_drain' => null,
];
try {
    fwrite(STDOUT, sprintf("Seeding %d rows per table on %s...\n", $options['rows'], $engine));
    perfSeed(
        $database,
        $jobs,
        ['id', 'queue', 'job_type', 'schema_version', 'payload', 'priority', 'status',
        'available_at', 'attempts', 'maximum_attempts', 'created_at', 'updated_at', 'execution_scope'],
        $options['rows'],
        static fn (int $index): array => [Uuid::uuid7()->toString(), $queue, 'perf.gauge', 1, '{}', 0,
        $index % 10 === 0 ? 'pending' : 'completed', $stamp('-1 hour'), 0, 1, $stamp('-2 hours'),
        $stamp('-2 hours'),
        'installation']
    );
    perfSeed($database, $outbox, ['event_id', 'event_type', 'schema_version', 'sensitivity', 'site_identifier',
        'aggregate_type', 'aggregate_id', 'aggregate_version', 'correlation_id', 'envelope', 'status',
        'available_at', 'attempts', 'maximum_attempts', 'retained_until', 'replay_count', 'created_at',
        'updated_at'], $options['rows'], static fn (int $index): array => [Uuid::uuid7()->toString(),
        'perf.gauge' . $nonce, 1, 'internal', 'perf', 'perf', (string) $index, 1, 'perf', '{}',
        $index % 10 === 0 ? 'pending' : 'dispatched', $stamp('-1 hour'), 0, 1, $stamp('+90 days'), 0,
        $stamp('-2 hours'), $stamp('-2 hours')]);
    if ($postgres) {
        $database->executeStatement(sprintf('ANALYZE %s, %s', $jobs, $outbox));
    } else {
        $database->fetchAllAssociative(sprintf('ANALYZE TABLE %s, %s', $jobs, $outbox));
    }
    $cap = 10_000;
    $statements = [
        'kumwe_jobs_pending' => [
            "SELECT COUNT(*) FROM $jobs WHERE status IN ('pending', 'reserved')",
            "SELECT COUNT(*) FROM (SELECT 1 AS probe FROM $jobs WHERE status IN ('pending', 'reserved') LIMIT $cap) bounded",
        ],
        'kumwe_jobs_dead' => [
            "SELECT COUNT(*) FROM $jobs WHERE status = 'dead'",
            "SELECT COUNT(*) FROM (SELECT 1 AS probe FROM $jobs WHERE status = 'dead' LIMIT $cap) bounded",
        ],
        'kumwe_jobs_oldest_due_age_seconds' => [
            "SELECT MIN(available_at) FROM $jobs WHERE status = 'pending'",
            "SELECT available_at FROM $jobs WHERE status = 'pending' AND available_at IS NOT NULL "
            . 'ORDER BY available_at LIMIT 1',
        ],
        'kumwe_outbox_pending' => [
            "SELECT COUNT(*) FROM $outbox WHERE status IN ('pending', 'reserved')",
            "SELECT COUNT(*) FROM (SELECT 1 AS probe FROM $outbox WHERE status IN ('pending', 'reserved') "
            . "LIMIT $cap) bounded",
        ],
        'kumwe_outbox_oldest_pending_age_seconds' => [
            "SELECT MIN(created_at) FROM $outbox WHERE status IN ('pending', 'reserved')",
            "SELECT created_at FROM $outbox WHERE status IN ('pending', 'reserved') AND created_at IS NOT NULL "
            . 'ORDER BY created_at LIMIT 1',
        ],
        'kumwe_retention_ingest_rows_per_second(outbox)' => [
            "SELECT COUNT(*) FROM $outbox WHERE created_at > '" . $stamp('-5 minutes') . "'",
            "SELECT COUNT(*) FROM (SELECT 1 AS probe FROM $outbox WHERE created_at > '" . $stamp('-5 minutes')
            . "' LIMIT 100000) bounded",
        ],
    ];
    foreach ($statements as $gauge => [$exact, $bounded]) {
        $report['gauges'][$gauge] = [
            'exact' => perfTime($database, $exact, $options['repeats'], $postgres),
            'bounded' => perfTime($database, $bounded, $options['repeats'], $postgres),
        ];
        fwrite(STDOUT, sprintf(
            "%-50s exact %8.3f ms  bounded %8.3f ms\n",
            $gauge,
            $report['gauges'][$gauge]['exact']['median_ms'],
            $report['gauges'][$gauge]['bounded']['median_ms'],
        ));
    }
    perfSeed($database, $idempotency, ['id', 'scope_digest', 'site_identifier', 'actor_id', 'operation',
        'operation_id', 'request_fingerprint', 'authorization_fingerprint', 'state', 'result', 'result_checksum',
        'created_at', 'expires_at'], $options['drain-rows'], static function (int $index) use ($stamp, $nonce): array {
            $id = Uuid::uuid7()->toString();

            return [$id, hash('sha256', $nonce . $id), 'perf', 'system:perf', 'business.record.update', $id,
                str_repeat('b', 64), str_repeat('c', 64), 'completed', '{}', str_repeat('d', 64), $stamp('-2 hours'),
                $stamp('-1 hour')];
        });
    $budget = RetentionCatalogue::declared()->policy(RetentionStore::BusinessIdempotency)->budget();
    $result = $drain->drain(RetentionStore::BusinessIdempotency, $budget, TestKernelFactory::workerContext($container));
    $rate = $result->rowsPerSecond();
    $report['retention_drain'] = [
        'store' => 'business_idempotency',
        'seeded_expired_rows' => $options['drain-rows'],
        'rows_drained' => $result->rowsDrained,
        'batches' => $result->batches,
        'final_batch' => $result->finalBatch,
        'elapsed_seconds' => round($result->elapsedSeconds, 3),
        'run_rows_per_second' => $rate === null ? null : round($rate, 1),
        'duty_cycle' => 0.5,
        'sustained_rows_per_second' => $rate === null ? null : round($rate * 0.5, 1),
        'required_sustained_rows_per_second' => RetentionCatalogue::REQUIRED_DRAIN_ROWS_PER_SECOND,
        'meets_requirement' => $rate !== null && $rate * 0.5 >= RetentionCatalogue::REQUIRED_DRAIN_ROWS_PER_SECOND,
        'note' => 'Single drain on an otherwise idle database; not measured under concurrent ordinary load.',
    ];
    fwrite(STDOUT, sprintf(
        "retention drain: %d rows in %.2f s, %d batches, final batch %d, run rate %.0f rows/s, sustained %.0f "
        . "(required %d)\n",
        $result->rowsDrained,
        $result->elapsedSeconds,
        $result->batches,
        $result->finalBatch,
        $rate ?? 0.0,
        ($rate ?? 0.0) * 0.5,
        RetentionCatalogue::REQUIRED_DRAIN_ROWS_PER_SECOND,
    ));
} finally {
    // Every seeded row is removed even when a measurement failed; each delete is attempted independently.
    foreach (
        [
            ["DELETE FROM $jobs WHERE job_type = ?", 'perf.gauge'],
            ["DELETE FROM $outbox WHERE event_type = ?", 'perf.gauge' . $nonce],
            ["DELETE FROM $idempotency WHERE site_identifier = ?", 'perf'],
        ] as [$sql, $value]
    ) {
        try {
            $database->executeStatement($sql, [$value]);
        } catch (Throwable $failure) {
            fwrite(STDERR, 'Cleanup failed: ' . $failure::class . "\n");
        }
    }
}
@mkdir($root . '/build/perf', 0775, true);
file_put_contents(
    $root . '/build/perf/gauges-' . $engine . '.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
);
fwrite(STDOUT, 'Report: build/perf/gauges-' . $engine . ".json\n");
