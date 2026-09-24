<?php

/**
 * Summarise host benchmark samples without treating serial latency as concurrent throughput.
 *
 * These functions belong to the development harness, not the application runtime or a package API.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

namespace Kumwe\App\Tools\Performance;

/**
 * Describe the observations, using nearest-rank percentiles and sample standard deviation.
 *
 * @param   list<float>  $values  Observed values in one declared unit.
 *
 * @return  array<string, int|float|null>  Empty samples retain null statistics, never invented zero latency.
 *
 * @since   2.0.0
 */
function sampleStatistics(array $values): array
{
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $mean = $count > 0 ? array_sum($values) / $count : null;
    $variance = $count > 1
        ? array_sum(array_map(static fn (float $value): float => ($value - $mean) ** 2, $values)) / ($count - 1)
        : null;
    $deviation = $variance === null ? null : sqrt($variance);
    $percentile = static fn (float $rank): ?float => $count === 0 ? null : $values[(int) ceil($rank * $count) - 1];

    return [
        'samples' => $count,
        'minimum' => $count > 0 ? $values[0] : null,
        'maximum' => $count > 0 ? $values[$count - 1] : null,
        'mean' => $mean,
        'p50' => $percentile(0.50),
        'p95' => $percentile(0.95),
        'p99' => $percentile(0.99),
        'sample_standard_deviation' => $deviation,
        'coefficient_of_variation' => $mean > 0 && $deviation !== null ? $deviation / $mean : null,
    ];
}

/**
 * Count overlapping application calls, including time spent waiting for database locks.
 *
 * @param   list<array{start_ns: int, end_ns: int}>  $intervals  Monotonic intervals from processes on one host.
 *
 * @return  int  Observed maximum in-flight calls; adjacent intervals do not overlap.
 *
 * @since   2.0.0
 */
function observedOverlap(array $intervals): int
{
    $events = [];
    foreach ($intervals as $interval) {
        if ($interval['end_ns'] <= $interval['start_ns']) {
            continue;
        }
        $events[] = [$interval['start_ns'], 1];
        $events[] = [$interval['end_ns'], -1];
    }
    sort($events);
    $active = 0;
    $maximum = 0;
    foreach ($events as [, $change]) {
        $active += $change;
        $maximum = max($maximum, $active);
    }

    return $maximum;
}

/**
 * Price a daily scenario from complete repeated measurements at the same worker count.
 *
 * @param   list<float>  $rates        Successful logical commits per wall-clock second in each repeat.
 * @param   int          $dailyTarget  Planning demand, not a claim the sample qualified.
 *
 * @return  array<string, mixed>  A time extrapolation with observed variation and explicit assumptions.
 *
 * @since   2.0.0
 */
function dailyScenario(array $rates, int $dailyTarget): array
{
    $stats = sampleStatistics($rates);

    return [
        'kind' => 'time_extrapolation_at_measured_worker_count',
        'planning_target_lbt_per_day' => $dailyTarget,
        'repeat_lbt_per_second' => $stats,
        'estimated_lbt_per_day' => $stats['mean'] === null ? null : $stats['mean'] * 86400,
        'observed_rate_range_scaled_to_day' => $rates === [] ? null : [min($rates) * 86400, max($rates) * 86400],
        'confidence_interval' => null,
        'supported_production_capacity' => null,
        'assumptions' => [
            'The sampled workload, data size, worker count, database and hardware remain unchanged for 86400 seconds.',
            'No CPU, memory or worker-count multiplier is applied to a shared database or a hot counter.',
            'The range describes repeat variation, not a confidence interval or a sustained-load guarantee.',
            'A short sample does not measure retention, queue drain, availability, recovery or the mixed envelope.',
        ],
    ];
}

/**
 * Start independent workers, wait for warm-up readiness, and release their shared measurement barrier.
 *
 * Each worker creates worker-N.ready and worker-N.json in the supplied directory. Logs go to files so a
 * full stderr pipe cannot deadlock the coordinator. Every exit, including deadline failure, reaps children.
 *
 * @param   list<list<string>>  $commands   Argument arrays passed directly to proc_open without a shell.
 * @param   string              $directory  Private handshake directory for this repeat.
 * @param   int                 $timeout    Deadline in seconds for each of readiness and measurement.
 *
 * @return  array{release_ns: int, workers: list<array<string, mixed>>, failures: list<string>}
 *
 * @since   2.0.0
 */
function runWorkers(array $commands, string $directory, int $timeout): array
{
    $processes = [];
    $failures = [];
    $workers = [];
    $release = 0;
    try {
        foreach ($commands as $index => $command) {
            $process = proc_open($command, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $directory . '/worker-' . $index . '.stdout.log', 'w'],
                2 => ['file', $directory . '/worker-' . $index . '.stderr.log', 'w'],
            ], $pipes);
            if (!is_resource($process)) {
                throw new \RuntimeException('worker_spawn_failed');
            }
            $processes[$index] = $process;
        }
        $deadline = hrtime(true) + $timeout * 1_000_000_000;
        do {
            $ready = 0;
            foreach ($processes as $index => $process) {
                clearstatcache(true, $directory . '/worker-' . $index . '.ready');
                $ready += (int) is_file($directory . '/worker-' . $index . '.ready');
                if (!proc_get_status($process)['running']) {
                    throw new \RuntimeException('worker_exited_before_barrier');
                }
            }
            if ($ready === count($commands)) {
                break;
            }
            if (hrtime(true) >= $deadline) {
                throw new \RuntimeException('worker_readiness_timeout');
            }
            usleep(1000);
        } while (true);
        $release = hrtime(true);
        file_put_contents($directory . '/start', (string) $release);
        $deadline = hrtime(true) + $timeout * 1_000_000_000;
        $remaining = $processes;
        while ($remaining !== []) {
            foreach ($remaining as $index => $process) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    continue;
                }
                unset($remaining[$index]);
                if ($status['exitcode'] !== 0) {
                    $failures[] = 'worker_' . $index . '_exit_' . $status['exitcode'];
                }
            }
            if ($remaining !== [] && hrtime(true) >= $deadline) {
                throw new \RuntimeException('worker_measurement_timeout');
            }
            if ($remaining !== []) {
                usleep(1000);
            }
        }
    } catch (\RuntimeException $failure) {
        $failures[] = $failure->getMessage();
    } finally {
        foreach ($processes as $process) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
        foreach (array_keys($commands) as $index) {
            $path = $directory . '/worker-' . $index . '.json';
            $outcome = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (!is_array($outcome)) {
                $failures[] = 'worker_' . $index . '_missing_result';
                continue;
            }
            $workers[] = $outcome;
        }
    }

    return ['release_ns' => $release, 'workers' => $workers, 'failures' => $failures];
}
