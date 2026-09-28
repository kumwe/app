<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Performance;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function Kumwe\App\Tools\Performance\capacityMarkdown;
use function Kumwe\App\Tools\Performance\confidenceInterval95;
use function Kumwe\App\Tools\Performance\fitScalability;
use function Kumwe\App\Tools\Performance\predictScalability;
use function Kumwe\App\Tools\Performance\studentT95;

/**
 * Holds the sampled-capacity statistics to their stated method: intervals, model fits and labelled estimates.
 *
 * The subject is `tools/PerfConcurrentSamples.php`, which is not under `src/`; the reasoned coverage list
 * carries `tests/Unit/Performance/`.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class ScalabilityModelTest extends TestCase
{
    /**
     * Load the development-only sampling helpers.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/tools/PerfConcurrentSamples.php';
    }

    /**
     * A t interval uses the repeat count, and a single repeat yields no interval at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConfidenceIntervalsUseStudentTAndNeverInventBounds(): void
    {
        self::assertSame(12.706, studentT95(1));
        self::assertSame(2.042, studentT95(35));
        self::assertSame(1.96, studentT95(500));
        $interval = confidenceInterval95([10.0, 20.0]);
        self::assertEqualsWithDelta(15.0 - 12.706 * 7.0710678 / 1.4142136, $interval['lower'], 1e-3);
        self::assertNull(confidenceInterval95([10.0])['lower']);
        $this->expectException(\InvalidArgumentException::class);
        studentT95(0);
    }

    /**
     * Exact USL data recovers its parameters, peak and a perfect fit, and predictions are labelled.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheUniversalScalabilityLawIsRecoveredFromExactData(): void
    {
        $points = [];
        foreach ([1, 2, 4, 8] as $n) {
            $points[] = ['workers' => $n, 'rate' => 10.0 * $n / (1 + 0.1 * ($n - 1) + 0.01 * $n * ($n - 1))];
        }
        $fit = fitScalability($points);
        self::assertSame('usl', $fit['prediction_model']);
        self::assertSame('good', $fit['fit_quality']);
        $usl = $fit['models']['usl'];
        self::assertEqualsWithDelta(10.0, $usl['lambda_lbt_per_second_per_worker'], 1e-6);
        self::assertEqualsWithDelta(0.1, $usl['sigma_contention'], 1e-6);
        self::assertEqualsWithDelta(0.01, $usl['kappa_coherence'], 1e-6);
        self::assertEqualsWithDelta(1.0, $usl['r_squared'], 1e-9);
        self::assertEqualsWithDelta(sqrt(0.9 / 0.01), $usl['peak_workers'], 1e-6);
        $predictions = predictScalability($usl, [1, 2, 4, 8], [4, 16], 5_000_000);
        self::assertSame('interpolation', $predictions[0]['kind']);
        self::assertSame('extrapolation', $predictions[1]['kind']);
        self::assertEqualsWithDelta(
            $predictions[1]['predicted_lbt_per_second'] * 86_400,
            $predictions[1]['predicted_lbt_per_day'],
            1e-6,
        );
    }

    /**
     * A negative fitted term is disclosed but never used for prediction; too few levels are not identifiable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPhysicallyInvalidFitsAreDisclosedButNotUsed(): void
    {
        $fit = fitScalability([
            ['workers' => 1, 'rate' => 10.0], ['workers' => 2, 'rate' => 25.0], ['workers' => 4, 'rate' => 30.0],
        ]);
        self::assertFalse($fit['models']['usl']['physically_valid']);
        self::assertNotSame([], $fit['models']['usl']['parameter_warnings']);
        self::assertNotSame('usl', $fit['prediction_model']);
        $narrow = fitScalability([['workers' => 1, 'rate' => 10.0], ['workers' => 2, 'rate' => 18.0]]);
        self::assertFalse($narrow['models']['usl']['identifiable']);
        self::assertSame([], predictScalability($narrow['models']['usl'], [1, 2], [8], 5_000_000));
    }

    /**
     * The markdown summary keeps measured results and model estimates in separate, labelled sections.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSummarySeparatesMeasurementFromEstimate(): void
    {
        $markdown = capacityMarkdown([
            'result_binding' => ['cpu_model' => 'Test CPU', 'logical_cpus_exposed' => 4],
            'plan' => ['workers' => [1, 2], 'samples' => 20, 'warmup' => 5, 'repeats' => 2],
            'passed' => true,
            'summary' => [[
                'operation' => 'ordinary_small_mutation', 'workers' => 1,
                'throughput_ci95' => confidenceInterval95([10.0, 12.0]), 'throughput_cv' => 0.1,
                'latency_ms' => ['p50' => 1.0, 'p95' => 2.0, 'p99' => 3.0], 'failed_calls' => 0,
                'maximum_observed_overlap' => 1,
            ]],
            'scalability' => ['ordinary_small_mutation' => fitScalability([
                ['workers' => 1, 'rate' => 10.0], ['workers' => 2, 'rate' => 18.0],
            ]) + ['predictions' => []]],
        ]);
        $measured = strpos($markdown, '## Measured');
        $estimated = strpos($markdown, '## Estimated (model output, not measurement)');
        self::assertIsInt($measured);
        self::assertIsInt($estimated);
        self::assertLessThan($estimated, $measured);
        self::assertStringContainsString('extrapolations', $markdown);
        self::assertStringContainsString('Test CPU', $markdown);
    }
}
