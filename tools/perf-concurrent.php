<?php

/**
 * Measure concurrent BusinessRecordService commits on the current test host (P7-A, ADR 0021).
 *
 * Invoke through tools/perf-harness.php --concurrent [--plan] [--workers=1,2,4] [--samples=30]
 * [--warmup=5] [--repeats=3] [--timeout=120]. Each worker owns a fresh kernel and connection. Warm-up,
 * authentication and fixture creation precede the shared measurement barrier. Failed calls remain evidence.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Ramsey\Uuid\Uuid;

use function Kumwe\App\Tools\Performance\dailyScenario;
use function Kumwe\App\Tools\Performance\observedOverlap;
use function Kumwe\App\Tools\Performance\runWorkers;
use function Kumwe\App\Tools\Performance\sampleStatistics;

require_once __DIR__ . '/PerfConcurrentSamples.php';

/**
 * Bind processes to the same configured datastore without exposing credentials.
 *
 * @return  string  Digest of driver, host, port, database and table prefix.
 *
 * @since   2.0.0
 */
function perfDatabaseFingerprint(): string
{
    $coordinates = [];
    foreach (['DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_TABLE_PREFIX'] as $key) {
        $coordinates[$key] = getenv($key) ?: '';
    }

    return hash('sha256', json_encode($coordinates, JSON_THROW_ON_ERROR));
}

/**
 * Execute one independent caller and publish its observations even when an operation fails.
 *
 * @param   string  $path  Coordinator-written worker configuration, containing no credentials.
 *
 * @return  int  Exit status; operation failures are retained for the coordinator's verdict.
 *
 * @since   2.0.0
 */
function perfSampleWorker(string $path): int
{
    $config = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    $directory = dirname($path);
    $worker = $config['worker'];
    $result = ['worker' => $worker, 'warmup' => [], 'samples' => [], 'fatal_error' => null];
    try {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $database = $container->get(Connection::class);
        if (!$records instanceof BusinessRecordService || !$database instanceof Connection) {
            throw new RuntimeException('The benchmark services are unavailable.');
        }
        $result['database_fingerprint'] = perfDatabaseFingerprint();
        $result['connection_id'] = (string) $database->fetchOne(
            getenv('DB_DRIVER') === 'pgsql' ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()',
        );
        $invoke = static function (string $phase, int $index) use ($records, $context, $config): array {
            $stem = $config['stem'] . '-' . $config['worker'] . '-' . $phase . '-' . $index;
            $recordId = Uuid::uuid7()->toString();
            $command = new CreateRecordCommand(
                $context,
                $config['handle'],
                NeutralBusinessFixture::recordValues('Concurrent sample ' . $stem),
                NeutralBusinessFixture::idempotencyKey('perf-' . $stem),
                recordId: $recordId,
            );
            $start = hrtime(true);
            $error = null;
            $replayed = false;
            try {
                $mutation = $records->create($command);
                $replayed = $mutation->replayed;
            } catch (Throwable $failure) {
                // Exception classes identify refusals without publishing SQL, request bodies or credentials.
                $error = $failure::class;
            }

            return [
                'start_ns' => $start,
                'end_ns' => hrtime(true),
                'record_id' => $recordId,
                'error' => $error,
                'replayed' => $replayed,
            ];
        };
        for ($index = 0; $index < $config['warmup']; $index++) {
            $result['warmup'][] = $invoke('warmup', $index);
        }
        file_put_contents($directory . '/worker-' . $worker . '.ready', 'ready');
        $deadline = hrtime(true) + $config['timeout'] * 1_000_000_000;
        while (!is_file($directory . '/start')) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('The coordinator did not release the measurement barrier.');
            }
            usleep(1000);
            clearstatcache(true, $directory . '/start');
        }
        for ($index = 0; $index < $config['samples']; $index++) {
            $result['samples'][] = $invoke('sample', $index);
        }
        $result['peak_php_memory_bytes'] = memory_get_peak_usage(true);
    } catch (Throwable $failure) {
        $result['fatal_error'] = $failure::class;
        $result['fatal_location'] = basename($failure->getFile()) . ':' . $failure->getLine();
    }
    file_put_contents(
        $directory . '/worker-' . $worker . '.json',
        json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
    );

    return $result['fatal_error'] === null ? 0 : 1;
}

/**
 * Record host resources as exposed to this process, preserving unknown container limits as null.
 *
 * @param   Connection  $database  The authoritative database used by every worker.
 * @param   string      $root      Checkout root.
 *
 * @return  array<string, mixed>  Host, runtime, source and allowlisted database settings.
 *
 * @since   2.0.0
 */
function perfSampleBinding(Connection $database, string $root): array
{
    $read = static fn (string $path): ?string => is_readable($path) ? trim((string) file_get_contents($path)) : null;
    $cpu = $read('/proc/cpuinfo') ?? '';
    preg_match('/^model name\s*:\s*(.+)$/m', $cpu, $model);
    preg_match('/^MemTotal:\s*(\d+) kB/m', $read('/proc/meminfo') ?? '', $memory);
    $settings = [];
    $names = getenv('DB_DRIVER') === 'pgsql'
        ? ['max_connections', 'shared_buffers', 'work_mem', 'synchronous_commit', 'fsync']
        : ['max_connections', 'innodb_buffer_pool_size', 'innodb_flush_log_at_trx_commit', 'sync_binlog'];
    foreach ($names as $name) {
        try {
            $settings[$name] = (string) $database->fetchOne(
                getenv('DB_DRIVER') === 'pgsql' ? 'SHOW ' . $name : 'SELECT @@' . $name,
            );
        } catch (Throwable) {
            $settings[$name] = null;
        }
    }
    $diff = (string) shell_exec('git -C ' . escapeshellarg($root) . ' diff --binary HEAD 2>/dev/null');

    return [
        'source_commit' => trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD')),
        'tracked_diff_sha256' => hash('sha256', $diff),
        'tracked_changes_present' => $diff !== '',
        'harness_sha256' => hash_file('sha256', $root . '/tools/perf-concurrent.php'),
        'sampling_helpers_sha256' => hash_file('sha256', __DIR__ . '/PerfConcurrentSamples.php'),
        'composer_lock_sha256' => hash_file('sha256', $root . '/composer.lock'),
        'capacity_contract_sha256' => hash_file('sha256', $root . '/docs/roadmap/capacity-contract.json'),
        'measured_at' => gmdate('c'),
        'runner' => getenv('GITHUB_ACTIONS') === 'true' ? 'github-actions-shared' : 'local-unqualified',
        'operating_system' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
        'cpu_model' => $model[1] ?? null,
        'logical_cpus_exposed' => $cpu === '' ? null : preg_match_all('/^processor\s*:/m', $cpu),
        'host_memory_bytes' => isset($memory[1]) ? (int) $memory[1] * 1024 : null,
        'cgroup_cpu_max' => $read('/sys/fs/cgroup/cpu.max'),
        'cgroup_cpuset' => $read('/sys/fs/cgroup/cpuset.cpus.effective'),
        'cgroup_memory_max' => $read('/sys/fs/cgroup/memory.max'),
        'php_version' => PHP_VERSION,
        'native_engine_version' => phpversion('kumwe_engine'),
        'database_driver' => getenv('DB_DRIVER') ?: null,
        'database_version' => (string) $database->fetchOne('SELECT VERSION()'),
        'database_settings' => $settings,
        'database_fingerprint' => perfDatabaseFingerprint(),
        'application_image_digest' => null,
        'dataset_seed' => null,
        'dataset' => 'Fresh unique single-site definitions; generated records accumulate between repeats.',
    ];
}

/**
 * Retain completed batches even when a later worker or the workflow is interrupted.
 *
 * @param   array<string, mixed>  $report        Complete or explicitly incomplete observations.
 * @param   string                $runDirectory  Unique evidence directory for this invocation.
 * @param   string                $root          Repository root.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function perfWriteCheckpoint(array $report, string $runDirectory, string $root): void
{
    $bytes = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
    file_put_contents($runDirectory . '/report.json.tmp', $bytes);
    rename($runDirectory . '/report.json.tmp', $runDirectory . '/report.json');
    file_put_contents($root . '/build/perf/concurrent.json.tmp', $bytes);
    rename($root . '/build/perf/concurrent.json.tmp', $root . '/build/perf/concurrent.json');
}

$root = dirname(__DIR__);
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--sample-worker=')) {
        if (getenv('APP_ENV') !== 'testing') {
            fwrite(STDERR, "Sampling workers require APP_ENV=testing.\n");
            exit(2);
        }
        require $root . '/vendor/autoload.php';
        exit(perfSampleWorker(substr($argument, strlen('--sample-worker='))));
    }
}
$options = ['samples' => 30, 'warmup' => 5, 'repeats' => 3, 'timeout' => 120];
$workerCounts = [1, 2, 4];
$planOnly = false;
foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--concurrent', '--run'], true)) {
        continue;
    }
    if ($argument === '--plan') {
        $planOnly = true;
        continue;
    }
    if (preg_match('/^--workers=([0-9,]+)$/D', $argument, $match) === 1) {
        $workerCounts = array_map('intval', explode(',', $match[1]));
        continue;
    }
    if (preg_match('/^--(samples|warmup|repeats|timeout)=(\d+)$/D', $argument, $match) === 1) {
        $options[$match[1]] = (int) $match[2];
        continue;
    }
    fwrite(STDERR, "Unknown concurrent sampling argument.\n");
    exit(2);
}
if (
    $options['samples'] < 1 || $options['samples'] > 1000 || $options['warmup'] > 100
    || $options['repeats'] < 2 || $options['repeats'] > 10 || $options['timeout'] < 1 || $options['timeout'] > 600
    || min($workerCounts) < 1 || max($workerCounts) > 16 || count(array_unique($workerCounts)) !== count($workerCounts)
) {
    fwrite(STDERR, "Bounds: unique workers 1..16; samples 1..1000; warmup 0..100; repeats 2..10; timeout 1..600.\n");
    exit(2);
}
sort($workerCounts);
$plan = $options + [
    'harness' => 'kumwe-concurrent-samples',
    'workers' => $workerCounts,
    'operations' => ['ordinary_small_mutation', 'hot_sequence_commit'],
    'samples_unit' => 'measured attempts per worker per repeat per operation',
    'warmup_unit' => 'unmeasured attempts per worker before each repeat barrier',
    'below_30_samples_per_worker' => $options['samples'] < 30,
    'scope' => 'Single-site callers sharing one authoritative database; one counter for the sequence class.',
];
if ($planOnly) {
    echo json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
if (getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "Concurrent sampling requires APP_ENV=testing and a disposable test database.\n");
    exit(2);
}
require $root . '/vendor/autoload.php';
$contract = json_decode((string) file_get_contents($root . '/docs/roadmap/capacity-contract.json'), true);
$container = TestKernelFactory::create(Environment::fromGlobals());
$context = TestKernelFactory::administratorContext($container);
$records = $container->get(BusinessRecordService::class);
$database = $container->get(Connection::class);
$installations = $container->get(BusinessSchemaInstallationRepository::class);
if (
    !$records instanceof BusinessRecordService || !$database instanceof Connection
    || !$installations instanceof BusinessSchemaInstallationRepository
) {
    throw new RuntimeException('The benchmark runtime is unavailable.');
}
$nonce = bin2hex(random_bytes(5));
$runDirectory = $root . '/build/perf/samples-' . $nonce;
mkdir($runDirectory, 0700, true);
$allPassed = true;
$report = [
    'harness' => $plan['harness'],
    'role' => 'sampled_concurrency_evidence_not_production_capacity',
    'plan' => $plan,
    'result_binding' => perfSampleBinding($database, $root),
    'measurements' => [],
    'estimates' => [],
    'complete' => false,
    'passed' => false,
    'write_amplification' => [
        'physical_row_mutations_per_lbt' => null,
        'reason' => 'Call timings do not count row updates; net growth is not physical write amplification.',
    ],
    'limitations' => [
        'Fresh single-site small records, not the aged four-business mixed enterprise envelope.',
        'No document-line, read, background-worker, retention, fault-recovery or long-duration workload.',
        'Overlapping call intervals include lock waits; they do not prove simultaneous database execution.',
        'Internal transaction retries are not instrumented; the harness performs no retry of failed calls.',
        'p99 is a nearest-rank observation and is usually the sample maximum at small sample sizes.',
        'Database row-mutation amplification, database resource saturation and storage IOPS are unmeasured.',
        'CPU and memory facts describe exposed host resources; unavailable container limits remain unknown.',
    ],
];
perfWriteCheckpoint($report, $runDirectory, $root);
foreach ($plan['operations'] as $operation) {
    $document = NeutralBusinessFixture::document(
        'sample' . substr($operation, 0, 3) . $nonce,
        Uuid::uuid7()->toString(),
    );
    if ($operation === 'hot_sequence_commit') {
        $document['fields'][] = [
            'handle' => 'document_number', 'label' => 'Document number', 'type' => 'core.sequence',
            'configuration' => ['scope' => 'site', 'reset' => 'never', 'prefix' => 'SAMPLE-', 'padding' => 6],
            'required' => true, 'nullable' => false, 'length' => 36, 'unique' => true, 'indexed' => true,
            'immutable_after_create' => true, 'server_only' => true, 'read_only' => true,
            'sortable' => true, 'filterable' => true,
        ];
    }
    $definition = NeutralBusinessFixture::install($container, $context, $document);
    $installation = $installations->find($definition->id);
    $table = $installation?->blueprint->table('record');
    if ($table === null) {
        throw new RuntimeException('The sampling fixture has no record table.');
    }
    $quotedTable = $database->getDatabasePlatform()->quoteSingleIdentifier($table->physicalName);
    $expectedRows = 0;
    foreach ($workerCounts as $workerCount) {
        $rates = [];
        $groupPassed = true;
        for ($repeat = 0; $repeat < $options['repeats']; $repeat++) {
            $directory = $runDirectory . '/' . $operation . '-' . $workerCount . '-' . $repeat;
            mkdir($directory, 0700);
            $commands = [];
            for ($worker = 0; $worker < $workerCount; $worker++) {
                $path = $directory . '/worker-' . $worker . '.config.json';
                file_put_contents($path, json_encode($options + [
                    'worker' => $worker,
                    'handle' => $definition->handle,
                    'stem' => $nonce . '-' . substr($operation, 0, 3) . '-' . $workerCount . '-' . $repeat,
                ], JSON_THROW_ON_ERROR));
                $commands[] = [PHP_BINARY, __FILE__, '--sample-worker=' . $path];
            }
            $batch = runWorkers($commands, $directory, $options['timeout']);
            $failures = $batch['failures'];
            $intervals = [];
            $latencies = [];
            $failedLatencies = [];
            $numbers = [];
            $identities = [];
            $sessions = [];
            $succeeded = 0;
            $warmupSucceeded = 0;
            $expectedNumber = $expectedRows;
            foreach ($batch['workers'] as $outcome) {
                if (($outcome['database_fingerprint'] ?? null) !== perfDatabaseFingerprint()) {
                    $failures[] = 'worker_datastore_mismatch';
                }
                if (($outcome['fatal_error'] ?? null) !== null) {
                    $failures[] = 'worker_fatal_error';
                }
                $sessions[] = $outcome['connection_id'] ?? null;
                foreach (['warmup', 'samples'] as $phase) {
                    foreach ($outcome[$phase] ?? [] as $sample) {
                        $duration = ($sample['end_ns'] - $sample['start_ns']) / 1_000_000;
                        if ($phase === 'samples') {
                            $intervals[] = ['start_ns' => $sample['start_ns'], 'end_ns' => $sample['end_ns']];
                        }
                        if ($sample['error'] !== null || $sample['replayed']) {
                            $failures[] = $phase . ':' . ($sample['error'] ?? 'unexpected_replay');
                            if ($phase === 'samples') {
                                $failedLatencies[] = $duration;
                            }
                            continue;
                        }
                        $phase === 'samples' ? $succeeded++ : $warmupSucceeded++;
                        if ($phase === 'samples') {
                            $latencies[] = $duration;
                        }
                        $identities[] = $sample['record_id'];
                        try {
                            $view = $records->read(new ReadRecordQuery(
                                $context,
                                $definition->handle,
                                $sample['record_id'],
                            ));
                            if ($view->version !== 1) {
                                $failures[] = 'unexpected_record_version';
                            }
                            if ($operation === 'hot_sequence_commit') {
                                $number = $view->values['document_number'] ?? null;
                                if (!is_string($number) || preg_match('/^SAMPLE-(\d+)$/D', $number, $match) !== 1) {
                                    $failures[] = 'missing_sequence_number';
                                } else {
                                    $numbers[] = (int) $match[1];
                                }
                            }
                        } catch (Throwable $failure) {
                            $failures[] = 'committed_record_unreadable:' . $failure::class;
                        }
                    }
                }
            }
            $expectedRows += $succeeded + $warmupSucceeded;
            $actualRows = (int) $database->fetchOne('SELECT COUNT(*) FROM ' . $quotedTable);
            if ($actualRows !== $expectedRows || count(array_unique($identities)) !== count($identities)) {
                $failures[] = 'record_count_or_identity_mismatch';
            }
            sort($numbers);
            if ($operation === 'hot_sequence_commit' && $numbers !== range($expectedNumber + 1, $expectedRows)) {
                $failures[] = 'sequence_not_contiguous';
            }
            if (count(array_unique($sessions)) !== $workerCount || in_array(null, $sessions, true)) {
                $failures[] = 'independent_database_sessions_not_proven';
            }
            $overlap = observedOverlap($intervals);
            if ($overlap < min(2, $workerCount)) {
                $failures[] = 'concurrent_overlap_not_observed';
            }
            if (count($intervals) !== $workerCount * $options['samples']) {
                $failures[] = 'incomplete_measurement';
            }
            if ($warmupSucceeded !== $workerCount * $options['warmup']) {
                $failures[] = 'incomplete_warmup';
            }
            $last = $intervals === [] ? $batch['release_ns'] : max(array_column($intervals, 'end_ns'));
            $seconds = max(0.0, ($last - $batch['release_ns']) / 1_000_000_000);
            $rate = $seconds > 0 ? $succeeded / $seconds : 0.0;
            $passed = $failures === [];
            $groupPassed = $groupPassed && $passed;
            $allPassed = $allPassed && $passed;
            $rates[] = $rate;
            $report['measurements'][] = [
                'operation' => $operation, 'workers' => $workerCount, 'repeat' => $repeat,
                'attempted' => count($intervals), 'successful_lbt' => $succeeded,
                'warmup_successful_lbt' => $warmupSucceeded,
                'failed_calls' => count($failedLatencies), 'failure_counts' => array_count_values($failures),
                'successful_latency_ms' => sampleStatistics($latencies),
                'failed_latency_ms' => sampleStatistics($failedLatencies),
                'wall_seconds_after_barrier' => $seconds, 'successful_lbt_per_second' => $rate,
                'observed_maximum_inflight_calls' => $overlap,
                'independent_database_sessions' => count(array_unique($sessions)),
                'integrity' => ['expected_records' => $expectedRows, 'stored_records' => $actualRows],
                'raw_worker_results' => substr($directory, strlen($root) + 1),
                'passed' => $passed,
            ];
            perfWriteCheckpoint($report, $runDirectory, $root);
            printf(
                "%s workers=%d repeat=%d commits=%d overlap=%d %s\n",
                $operation,
                $workerCount,
                $repeat,
                $succeeded,
                $overlap,
                $passed ? 'PASS' : 'FAIL'
            );
        }
        $report['estimates'][] = [
            'operation' => $operation, 'workers' => $workerCount,
            'measured_worker_range' => [min($workerCounts), max($workerCounts)],
            'scenario' => $groupPassed ? dailyScenario($rates, $contract['profiles']['enterprise']['daily_lbt']) : null,
            'withheld_reason' => $groupPassed ? null : 'A repeat failed; no successful-only estimate is emitted.',
        ];
    }
}
$report['complete'] = true;
$report['passed'] = $allPassed;
$schema = json_decode((string) file_get_contents($root . '/docs/quality/perf-report.schema.json'), true);
if (!is_array($schema['concurrent']['required'] ?? null) || $schema['concurrent']['required'] === []) {
    throw new RuntimeException('The concurrent result schema is missing.');
}
foreach ($schema['concurrent']['required'] as $key => $type) {
    if (
        !array_key_exists($key, $report) || ($type === 'boolean' ? !is_bool($report[$key])
        : ($type === 'string' ? !is_string($report[$key]) : !is_array($report[$key])))
    ) {
        throw new RuntimeException('The concurrent report violates its result schema.');
    }
}
perfWriteCheckpoint($report, $runDirectory, $root);
echo "Concurrent report: build/perf/concurrent.json\n";
exit($report['passed'] ? 0 : 1);
