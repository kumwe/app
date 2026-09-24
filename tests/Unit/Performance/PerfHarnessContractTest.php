<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Performance;

use Kumwe\App\Tools\PerfBreakpointStability;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function Kumwe\App\Tools\Performance\dailyScenario;
use function Kumwe\App\Tools\Performance\observedOverlap;
use function Kumwe\App\Tools\Performance\runWorkers;
use function Kumwe\App\Tools\Performance\sampleStatistics;

/**
 * Holds the performance harness to the three promises its documents rest on.
 *
 * The subject is `tools/perf-harness.php`, which is not under `src/` and not part of the released
 * package, so these cases attribute nothing; the reasoned coverage list carries the entry. What they
 * pin is the deterministic surface a characterisation depends on: the same seed always prints the same
 * plan byte for byte, a different seed prints a different one, and the declared result schema stays a
 * real document naming the shapes the harness holds its own output to before writing it.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class PerfHarnessContractTest extends TestCase
{
    /**
     * Load the development-only sampling helpers without expanding production autoload ownership.
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
     * One seed always derives one plan, byte for byte, and another seed derives another.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePlanIsDeterministicPerSeed(): void
    {
        $first = $this->plan(41);
        $second = $this->plan(41);
        $other = $this->plan(43);

        self::assertNotSame('', trim($first), 'The plan mode must print a plan.');
        self::assertSame($first, $second, 'One seed must always derive the byte-identical plan.');
        self::assertNotSame($first, $other, 'A different seed must derive a different plan.');
        $decoded = json_decode($first, true);
        self::assertIsArray($decoded);
        self::assertSame('docs/quality/perf-report.schema.json', $decoded['schema'] ?? null);
    }

    /**
     * The declared result schema names both document kinds and types every required key.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheResultSchemaDeclaresBothDocumentKinds(): void
    {
        $schema = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/docs/quality/perf-report.schema.json'),
            true,
        );
        self::assertIsArray($schema);
        foreach (['report', 'breakpoint', 'concurrent'] as $section) {
            self::assertIsArray($schema[$section] ?? null, sprintf('Section "%s" is missing.', $section));
            $required = $schema[$section]['required'] ?? null;
            self::assertIsArray($required);
            self::assertNotSame([], $required, sprintf('Section "%s" requires nothing.', $section));
            foreach ($required as $key => $type) {
                self::assertIsString($key);
                self::assertContains(
                    $type,
                    ['object', 'array', 'number', 'string', 'boolean'],
                    sprintf('Key "%s" declares a type outside the schema vocabulary.', $key),
                );
            }
        }
        self::assertArrayHasKey(
            'write_amplification',
            $schema['report']['required'],
            'A report without its PRM/LBT figure is one the capacity contract forbids publishing.',
        );
        self::assertArrayHasKey(
            'stable',
            $schema['breakpoint']['required'],
            'The exit gate asks for a stable breakpoint report, so the document must say whether it is.',
        );
    }

    /**
     * Empty and singleton observations cannot manufacture zero latency or a variance estimate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSamplingStatisticsPreserveUnknownsAndUseSampleVariance(): void
    {
        self::assertNull(sampleStatistics([])['mean']);
        self::assertNull(sampleStatistics([10.0])['sample_standard_deviation']);
        $stats = sampleStatistics([10.0, 20.0, 30.0]);
        self::assertSame(20.0, $stats['mean']);
        self::assertSame(10.0, $stats['sample_standard_deviation']);
        self::assertSame(30.0, $stats['p95']);
    }

    /**
     * Sequential calls cannot satisfy the concurrency gate merely because many workers were requested.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyOverlappingCallIntervalsCountAsConcurrent(): void
    {
        self::assertSame(1, observedOverlap([
            ['start_ns' => 10, 'end_ns' => 20],
            ['start_ns' => 20, 'end_ns' => 30],
        ]));
        self::assertSame(2, observedOverlap([
            ['start_ns' => 10, 'end_ns' => 21],
            ['start_ns' => 20, 'end_ns' => 30],
        ]));
        self::assertSame(0, observedOverlap([]));
    }

    /**
     * A daily scenario scales observed time only and carries no production or confidence claim.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDailyEstimateKeepsMeasuredRepeatVariationSeparateFromCapacity(): void
    {
        $scenario = dailyScenario([10.0, 20.0, 30.0], 5000000);
        self::assertSame(1728000.0, $scenario['estimated_lbt_per_day']);
        self::assertSame([864000.0, 2592000.0], $scenario['observed_rate_range_scaled_to_day']);
        self::assertNull($scenario['supported_production_capacity']);
        self::assertNull($scenario['confidence_interval']);
        self::assertSame(3, $scenario['repeat_lbt_per_second']['samples']);
    }

    /**
     * The executable plan states its counting unit and refuses worker counts outside the bounded workload.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConcurrentPlanDeclaresSamplesPerWorkerAndRejectsUnboundedWorkers(): void
    {
        $tool = dirname(__DIR__, 3) . '/tools/perf-harness.php';
        foreach (['1,2' => 0, '1,17' => 2, '0' => 2, '2,2' => 2] as $workers => $expectedExit) {
            $process = proc_open(
                [PHP_BINARY, $tool, '--concurrent', '--plan', '--workers=' . $workers],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame($expectedExit, proc_close($process));
            if ($expectedExit === 0) {
                $plan = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
                self::assertSame([1, 2], $plan['workers']);
                self::assertSame(30, $plan['samples']);
                self::assertSame('measured attempts per worker per repeat per operation', $plan['samples_unit']);
            }
        }
    }

    /**
     * A worker dying before readiness produces a failed result without waiting for the full deadline.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWorkerFailureIsRetainedInsteadOfBecomingAZeroLatencySample(): void
    {
        $directory = sys_get_temp_dir() . '/kumwe-perf-worker-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        try {
            $result = runWorkers([[PHP_BINARY, '-r', 'exit(17);']], $directory, 5);
            self::assertContains('worker_exited_before_barrier', $result['failures']);
            self::assertSame([], $result['workers']);
            self::assertSame(0, $result['release_ns']);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    /**
     * A ready worker that stalls after barrier release is terminated and reported as incomplete.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStalledWorkerCannotLeaveTheCoordinatorWaitingIndefinitely(): void
    {
        $directory = sys_get_temp_dir() . '/kumwe-perf-timeout-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        try {
            $script = 'file_put_contents($argv[1] . "/worker-0.ready", "ready"); sleep(30);';
            $result = runWorkers([[PHP_BINARY, '-r', $script, $directory]], $directory, 1);
            self::assertContains('worker_measurement_timeout', $result['failures']);
            self::assertContains('worker_0_missing_result', $result['failures']);
            self::assertGreaterThan(0, $result['release_ns']);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    /**
     * A changed knee is a changed breakpoint even when every individual p95 pair is close.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBreakpointKneeDisagreementIsAlwaysUnstable(): void
    {
        self::assertFalse($this->stable(
            500,
            1000,
            [['first_p95_ms' => 4600.0, 'second_p95_ms' => 4620.0, 'budget_p95_ms' => 4666.7]],
        ));
    }

    /**
     * A p95 delta that is large relative to both the observation and its budget remains unstable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMaterialRelativeAndAbsoluteDivergenceIsUnstable(): void
    {
        self::assertFalse($this->stable(
            null,
            null,
            [['first_p95_ms' => 800.0, 'second_p95_ms' => 1600.0, 'budget_p95_ms' => 2000.0]],
        ));
    }

    /**
     * Shared-runner jitter far below the objective is not promoted into a false breakpoint failure.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHighRelativeButImmaterialAbsoluteJitterIsStable(): void
    {
        self::assertTrue($this->stable(
            null,
            null,
            [
                ['first_p95_ms' => 18.3, 'second_p95_ms' => 18.1, 'budget_p95_ms' => 2000.0],
                ['first_p95_ms' => 22.6, 'second_p95_ms' => 44.7, 'budget_p95_ms' => 2666.7],
                ['first_p95_ms' => 37.7, 'second_p95_ms' => 60.3, 'budget_p95_ms' => 4666.7],
                ['first_p95_ms' => 67.9, 'second_p95_ms' => 62.6, 'budget_p95_ms' => 8000.0],
            ],
        ));
        self::assertTrue($this->stable(
            null,
            null,
            [
                ['first_p95_ms' => 20.0, 'second_p95_ms' => 19.9, 'budget_p95_ms' => 2000.0],
                ['first_p95_ms' => 38.3, 'second_p95_ms' => 22.9, 'budget_p95_ms' => 2666.7],
                ['first_p95_ms' => 38.8, 'second_p95_ms' => 38.2, 'budget_p95_ms' => 4666.7],
                ['first_p95_ms' => 390.1, 'second_p95_ms' => 62.8, 'budget_p95_ms' => 8000.0],
            ],
        ));
    }

    /**
     * Print the deterministic plan for one seed through the real tool.
     *
     * @param   int  $seed  Generator seed under test.
     *
     * @return  string  Raw plan output.
     *
     * @since   2.0.0
     */
    private function plan(int $seed): string
    {
        $root = dirname(__DIR__, 3);
        $output = shell_exec(sprintf(
            '%s %s --plan --seed=%d 2>/dev/null',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/tools/perf-harness.php'),
            $seed,
        ));

        return is_string($output) ? $output : '';
    }

    /**
     * Evaluate a deterministic breakpoint comparison through the tool's production rule.
     *
     * @param   int|null                                                                                     $firstKnee
     *     First line count to cross its objective.
     * @param   int|null                                                                                     $secondKnee
     *     Second line count to cross its objective.
     * @param   list<array{first_p95_ms: float, second_p95_ms: float, budget_p95_ms: float}>                  $pairs
     *     Corresponding p95 observations and budgets.
     *
     * @return  bool  Whether the comparison is stable.
     *
     * @since   2.0.0
     */
    private function stable(?int $firstKnee, ?int $secondKnee, array $pairs): bool
    {
        require_once dirname(__DIR__, 3) . '/tools/PerfBreakpointStability.php';

        return PerfBreakpointStability::agrees($firstKnee, $secondKnee, $pairs);
    }
}
