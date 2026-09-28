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

/**
 * Two-sided 95% Student t quantile for the given degrees of freedom.
 *
 * @param   int  $degrees  Degrees of freedom, at least one.
 *
 * @return  float  Quantile; the normal 1.96 beyond 120 degrees.
 *
 * @since   2.0.0
 */
function studentT95(int $degrees): float
{
    $table = [1 => 12.706, 2 => 4.303, 3 => 3.182, 4 => 2.776, 5 => 2.571, 6 => 2.447, 7 => 2.365, 8 => 2.306,
        9 => 2.262, 10 => 2.228, 12 => 2.179, 15 => 2.131, 20 => 2.086, 25 => 2.060, 30 => 2.042, 40 => 2.021,
        60 => 2.000, 120 => 1.980];
    if ($degrees < 1) {
        throw new \InvalidArgumentException('A t quantile needs at least one degree of freedom.');
    }
    if ($degrees > 120) {
        return 1.960;
    }
    $chosen = 12.706;
    foreach ($table as $df => $value) {
        if ($df <= $degrees) {
            $chosen = $value;
        }
    }

    return $chosen;
}

/**
 * Mean and 95% confidence interval of repeated measurements, assuming independent repeats.
 *
 * @param   list<float>  $values  Repeat-level observations in one unit.
 *
 * @return  array{samples: int, mean: ?float, lower: ?float, upper: ?float, half_width: ?float, method: string}
 *          Null bounds when fewer than two repeats exist; the interval is never invented.
 *
 * @since   2.0.0
 */
function confidenceInterval95(array $values): array
{
    $stats = sampleStatistics($values);
    $count = count($values);
    if ($count < 2 || $stats['sample_standard_deviation'] === null || $stats['mean'] === null) {
        return ['samples' => $count, 'mean' => $stats['mean'], 'lower' => null, 'upper' => null,
            'half_width' => null, 'method' => 'student_t_95_two_sided'];
    }
    $half = studentT95($count - 1) * $stats['sample_standard_deviation'] / sqrt($count);

    return ['samples' => $count, 'mean' => $stats['mean'], 'lower' => $stats['mean'] - $half,
        'upper' => $stats['mean'] + $half, 'half_width' => $half, 'method' => 'student_t_95_two_sided'];
}

/**
 * Solve a small least-squares problem by normal equations.
 *
 * @param   list<list<float>>  $rows     Design matrix rows.
 * @param   list<float>        $targets  Observed values.
 *
 * @return  ?list<float>  Coefficients, or null when the system is singular.
 *
 * @since   2.0.0
 */
function leastSquares(array $rows, array $targets): ?array
{
    $width = count($rows[0] ?? []);
    $matrix = array_fill(0, $width, array_fill(0, $width + 1, 0.0));
    foreach ($rows as $index => $row) {
        for ($i = 0; $i < $width; $i++) {
            for ($j = 0; $j < $width; $j++) {
                $matrix[$i][$j] += $row[$i] * $row[$j];
            }
            $matrix[$i][$width] += $row[$i] * $targets[$index];
        }
    }
    for ($column = 0; $column < $width; $column++) {
        $pivot = $column;
        for ($row = $column + 1; $row < $width; $row++) {
            if (abs($matrix[$row][$column]) > abs($matrix[$pivot][$column])) {
                $pivot = $row;
            }
        }
        if (abs($matrix[$pivot][$column]) < 1e-12) {
            return null;
        }
        [$matrix[$column], $matrix[$pivot]] = [$matrix[$pivot], $matrix[$column]];
        for ($row = 0; $row < $width; $row++) {
            if ($row === $column) {
                continue;
            }
            $factor = $matrix[$row][$column] / $matrix[$column][$column];
            for ($k = $column; $k <= $width; $k++) {
                $matrix[$row][$k] -= $factor * $matrix[$column][$k];
            }
        }
    }
    $solution = [];
    for ($i = 0; $i < $width; $i++) {
        $solution[] = $matrix[$i][$width] / $matrix[$i][$i];
    }

    return $solution;
}

/**
 * Fit one linearised scalability model with a chosen subset of its contention and coherence terms.
 *
 * @param   list<array{workers: int, rate: float}>  $points  Measured repeats.
 * @param   bool                                    $sigma   Whether σ is fitted; false fixes it at zero.
 * @param   bool                                    $kappa   Whether κ is fitted; false fixes it at zero.
 *
 * @return  ?array<string, mixed>  Parameters and fit statistics, or null when the system is singular.
 *
 * @since   2.0.0
 */
function fitScalabilityModel(array $points, bool $sigma, bool $kappa): ?array
{
    $rows = [];
    $targets = [];
    foreach ($points as $point) {
        $n = (float) $point['workers'];
        $row = [1.0];
        if ($sigma) {
            $row[] = $n - 1.0;
        }
        if ($kappa) {
            $row[] = $n * ($n - 1.0);
        }
        $rows[] = $row;
        $targets[] = $n / $point['rate'];
    }
    $coefficients = leastSquares($rows, $targets);
    if ($coefficients === null || $coefficients[0] <= 0.0) {
        return null;
    }
    $lambda = 1.0 / $coefficients[0];
    $fittedSigma = $sigma ? $coefficients[1] * $lambda : 0.0;
    $fittedKappa = $kappa ? $coefficients[$sigma ? 2 : 1] * $lambda : 0.0;
    $predict = static fn (float $n): float => $lambda * $n
        / (1.0 + $fittedSigma * ($n - 1.0) + $fittedKappa * $n * ($n - 1.0));
    $mean = array_sum(array_column($points, 'rate')) / count($points);
    $residual = 0.0;
    $total = 0.0;
    foreach ($points as $point) {
        $residual += ($point['rate'] - $predict((float) $point['workers'])) ** 2;
        $total += ($point['rate'] - $mean) ** 2;
    }
    $peak = $fittedKappa > 0.0 && $fittedSigma < 1.0 ? sqrt((1.0 - $fittedSigma) / $fittedKappa) : null;
    $parameters = 1 + ($sigma ? 1 : 0) + ($kappa ? 1 : 0);

    return [
        'identifiable' => true,
        'lambda_lbt_per_second_per_worker' => $lambda,
        'sigma_contention' => $fittedSigma,
        'kappa_coherence' => $fittedKappa,
        'fitted_terms' => array_values(array_filter(['lambda', $sigma ? 'sigma' : null, $kappa ? 'kappa' : null])),
        'r_squared' => $total > 0.0 ? 1.0 - $residual / $total : null,
        'relative_rmse' => $mean > 0.0 ? sqrt($residual / count($points)) / $mean : null,
        'points' => count($points),
        'degrees_of_freedom' => count($points) - $parameters,
        'peak_workers' => $peak,
        'peak_lbt_per_second' => $peak === null ? null : $predict($peak),
        'asymptotic_lbt_per_second' => $fittedKappa === 0.0 && $fittedSigma > 0.0 ? $lambda / $fittedSigma : null,
        'physically_valid' => $fittedSigma >= 0.0 && $fittedKappa >= 0.0,
        'parameter_warnings' => array_values(array_filter([
            $fittedSigma < 0.0 ? 'Fitted σ is negative: superlinear or noise-dominated scaling in the sample.' : null,
            $fittedKappa < 0.0 ? 'Fitted κ is negative: no retrograde region is identifiable from the sample.' : null,
        ])),
    ];
}

/**
 * Fit the Universal Scalability Law and Amdahl's law to measured throughput by concurrency.
 *
 * USL: X(N) = λN / (1 + σ(N − 1) + κN(N − 1)); Amdahl is the κ = 0 case. Each is fitted by the standard
 * linearisation N / X(N) = 1/λ + (σ/λ)(N − 1) + (κ/λ)N(N − 1) with least squares over every passing
 * repeat, and R² and relative RMSE are computed on X itself. A noisy sample can yield a negative σ or κ,
 * which has no physical meaning; the unconstrained fit is kept for disclosure and constrained refits
 * with the offending term fixed at zero are added. The prediction model is the physically valid fit with
 * the highest R²; when none is valid no prediction is made. Fit quality is labelled good (R² ≥ 0.8), fair
 * (≥ 0.5) or poor, and a poor fit's predictions are published only with that label.
 *
 * @param   list<array{workers: int, rate: float}>  $points  One entry per measured repeat.
 *
 * @return  array<string, mixed>  Fits, the chosen prediction model and identifiability notes.
 *
 * @since   2.0.0
 */
function fitScalability(array $points): array
{
    $points = array_values(array_filter($points, static fn (array $point): bool => $point['rate'] > 0.0));
    $levels = array_values(array_unique(array_map(static fn (array $point): int => $point['workers'], $points)));
    sort($levels);
    $specifications = [
        'usl' => [true, true, 3],
        'usl_sigma_zero' => [false, true, 2],
        'amdahl' => [true, false, 2],
    ];
    $models = [];
    $chosen = null;
    foreach ($specifications as $name => [$sigma, $kappa, $minimumLevels]) {
        if (count($levels) < $minimumLevels) {
            $models[$name] = ['identifiable' => false, 'reason' => sprintf(
                'Needs at least %d distinct worker counts; %d measured.',
                $minimumLevels,
                count($levels),
            )];
            continue;
        }
        $model = fitScalabilityModel($points, $sigma, $kappa);
        $models[$name] = $model ?? ['identifiable' => false, 'reason' => 'The least-squares system is singular.'];
        if (
            $model !== null && $model['physically_valid'] === true && $model['r_squared'] !== null
            && ($chosen === null || $model['r_squared'] > $models[$chosen]['r_squared'])
        ) {
            $chosen = $name;
        }
    }
    $quality = $chosen === null ? 'no_valid_model'
        : ($models[$chosen]['r_squared'] >= 0.8 ? 'good' : ($models[$chosen]['r_squared'] >= 0.5 ? 'fair' : 'poor'));

    return [
        'measured_worker_counts' => $levels,
        'models' => $models,
        'prediction_model' => $chosen,
        'fit_quality' => $quality,
    ];
}

/**
 * Predict throughput for larger topologies from a fitted model and price the daily figure.
 *
 * Predictions within the measured worker range are interpolations; every other one is an extrapolation
 * and is flagged. Total concurrency is what the model sees, so N application servers each running W
 * workers against one database are the same prediction as N × W workers on one server: the shared
 * database and the shared hot rows are the bottleneck the fit describes, and application CPU beyond the
 * measured host is assumed not to be the constraint — an assumption this function states rather than
 * tests. The daily figure multiplies the predicted per-second rate by 86,400 against the planning target.
 *
 * @param   array<string, mixed>  $model        One entry of `fitScalability()['models']`.
 * @param   list<int>             $measured     Distinct worker counts measured.
 * @param   list<int>             $concurrency  Total concurrent callers to predict for.
 * @param   int                   $dailyTarget  Planning target in LBT per day.
 *
 * @return  list<array<string, mixed>>  One prediction per concurrency level; empty for an unidentifiable fit.
 *
 * @since   2.0.0
 */
function predictScalability(array $model, array $measured, array $concurrency, int $dailyTarget): array
{
    if (($model['identifiable'] ?? false) !== true || $measured === []) {
        return [];
    }
    $lambda = (float) $model['lambda_lbt_per_second_per_worker'];
    $sigma = (float) $model['sigma_contention'];
    $kappa = (float) $model['kappa_coherence'];
    $predictions = [];
    foreach ($concurrency as $n) {
        $denominator = 1.0 + $sigma * ($n - 1) + $kappa * $n * ($n - 1);
        $rate = $denominator > 0.0 ? $lambda * $n / $denominator : null;
        $predictions[] = [
            'total_concurrent_callers' => $n,
            'kind' => $n >= min($measured) && $n <= max($measured) ? 'interpolation' : 'extrapolation',
            'predicted_lbt_per_second' => $rate,
            'predicted_lbt_per_day' => $rate === null ? null : $rate * 86_400,
            'fraction_of_planning_target' => $rate === null ? null : $rate * 86_400 / $dailyTarget,
        ];
    }

    return $predictions;
}

/**
 * Render the report's measured results and estimates as separate markdown sections.
 *
 * @param   array<string, mixed>  $report  Complete concurrent report.
 *
 * @return  string  Markdown summary for the workflow step summary and the retained artifact.
 *
 * @since   2.0.0
 */
function capacityMarkdown(array $report): string
{
    $binding = $report['result_binding'] ?? [];
    $lines = ['# Sampled concurrent capacity', ''];
    $lines[] = sprintf(
        'Host: %s × %s, %s GiB RAM; %s %s; PHP %s; runner %s; commit %s.',
        $binding['logical_cpus_exposed'] ?? '?',
        $binding['cpu_model'] ?? 'unknown CPU',
        isset($binding['host_memory_bytes']) ? round($binding['host_memory_bytes'] / 1_073_741_824, 1) : '?',
        $binding['database_driver'] ?? '?',
        $binding['database_version'] ?? '?',
        $binding['php_version'] ?? '?',
        $binding['runner'] ?? '?',
        substr((string) ($binding['source_commit'] ?? ''), 0, 12),
    );
    $plan = $report['plan'] ?? [];
    $lines[] = sprintf(
        'Plan: workers %s, %d measured calls per worker per repeat after %d warm-up, %d repeats. Passed: %s.',
        implode(',', $plan['workers'] ?? []),
        $plan['samples'] ?? 0,
        $plan['warmup'] ?? 0,
        $plan['repeats'] ?? 0,
        ($report['passed'] ?? false) ? 'yes' : 'no',
    );
    $lines[] = '';
    $lines[] = '## Measured';
    $lines[] = '';
    $lines[] = '| Operation | Workers | Repeats | LBT/s mean | 95% CI | CV | p50 ms | p95 ms | p99 ms | Errors | Overlap |';
    $lines[] = '|---|---|---|---|---|---|---|---|---|---|---|';
    foreach ($report['summary'] ?? [] as $row) {
        $ci = $row['throughput_ci95'];
        $lines[] = sprintf(
            '| %s | %d | %d | %.1f | %s | %s | %s | %s | %s | %d | %d |',
            $row['operation'],
            $row['workers'],
            $ci['samples'],
            $ci['mean'] ?? 0.0,
            $ci['lower'] === null ? 'n/a' : sprintf('%.1f–%.1f', $ci['lower'], $ci['upper']),
            $row['throughput_cv'] === null ? 'n/a' : sprintf('%.2f', $row['throughput_cv']),
            number_format((float) ($row['latency_ms']['p50'] ?? 0.0), 1),
            number_format((float) ($row['latency_ms']['p95'] ?? 0.0), 1),
            number_format((float) ($row['latency_ms']['p99'] ?? 0.0), 1),
            $row['failed_calls'],
            $row['maximum_observed_overlap'],
        );
    }
    $lines[] = '';
    $lines[] = '## Estimated (model output, not measurement)';
    $lines[] = '';
    foreach ($report['scalability'] ?? [] as $operation => $fit) {
        $lines[] = sprintf(
            '- %s: prediction model %s, fit quality %s.',
            $operation,
            $fit['prediction_model'] ?? 'none',
            $fit['fit_quality'] ?? 'unknown',
        );
        foreach ($fit['models'] as $name => $model) {
            if (($model['identifiable'] ?? false) !== true) {
                $lines[] = sprintf('  - %s %s: not identifiable — %s', $operation, strtoupper($name), $model['reason']);
                continue;
            }
            $lines[] = sprintf(
                '  - %s %s%s: λ=%.2f LBT/s/worker, σ=%.4f, κ=%.5f, R²=%s, rel. RMSE=%s, peak N=%s',
                $operation,
                strtoupper($name),
                $name === ($fit['prediction_model'] ?? null) ? ' (prediction model)' : '',
                $model['lambda_lbt_per_second_per_worker'],
                $model['sigma_contention'],
                $model['kappa_coherence'],
                $model['r_squared'] === null ? 'n/a' : sprintf('%.3f', $model['r_squared']),
                $model['relative_rmse'] === null ? 'n/a' : sprintf('%.3f', $model['relative_rmse']),
                $model['peak_workers'] === null ? 'none' : sprintf('%.1f', $model['peak_workers']),
            );
        }
        foreach ($fit['predictions'] ?? [] as $prediction) {
            $lines[] = sprintf(
                '    - %d callers (%s): %s LBT/s ≈ %s LBT/day (%s of 5,000,000)',
                $prediction['total_concurrent_callers'],
                $prediction['kind'],
                $prediction['predicted_lbt_per_second'] === null ? 'n/a'
                    : number_format($prediction['predicted_lbt_per_second'], 1),
                $prediction['predicted_lbt_per_day'] === null ? 'n/a'
                    : number_format($prediction['predicted_lbt_per_day'], 0),
                $prediction['fraction_of_planning_target'] === null ? 'n/a'
                    : sprintf('%.0f%%', 100 * $prediction['fraction_of_planning_target']),
            );
        }
    }
    $lines[] = '';
    $lines[] = 'Estimates are model outputs from a short sample on this host; values outside the measured worker range '
        . 'are extrapolations and none is a supported production capacity. See docs/operations/capacity-estimate.md.';

    return implode("\n", $lines) . "\n";
}
