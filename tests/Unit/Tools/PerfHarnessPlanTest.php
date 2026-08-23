<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Proves the performance harness plan is a pure function of its seed.
 *
 * The capacity contract's benchmark method binds every result to "dataset generator seed and age
 * distribution", which is only meaningful if the same seed always derives the same plan. These
 * tests hold the P2-I harness to that: byte-identical output for one seed, a different plan for a
 * different seed, and the contract vocabulary present in the emitted document.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class PerfHarnessPlanTest extends TestCase
{
    /**
     * The same seed derives byte-identical plans and a different seed derives a different one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePlanIsDeterministicPerSeed(): void
    {
        $first = $this->plan(1400);
        $second = $this->plan(1400);
        $other = $this->plan(7);

        self::assertSame($first, $second, 'One seed must always derive one plan.');
        self::assertNotSame($first, $other, 'Different seeds must not collide on one plan.');
    }

    /**
     * The plan speaks the capacity contract's vocabulary rather than inventing its own.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePlanCarriesTheContractVocabulary(): void
    {
        $decoded = json_decode($this->plan(1400), true);

        self::assertIsArray($decoded);
        self::assertSame('kumwe-perf-harness', $decoded['harness'] ?? null);
        self::assertIsArray($decoded['profile_target'] ?? null);
        self::assertSame(1000000, $decoded['profile_target']['daily_lbt'] ?? null);
        self::assertIsArray($decoded['reference_shape'] ?? null);
        self::assertSame(4, $decoded['reference_shape']['businesses'] ?? null);
        $classes = array_column($decoded['operation_classes'] ?? [], 'class');
        self::assertContains('document_100_line_commit', $classes);
        self::assertContains('document_1000_line_commit', $classes);
        self::assertContains('bounded_primary_key_read', $classes);
    }

    /**
     * Derive one plan through the real tool in plan mode.
     *
     * @param   int  $seed  Generator seed handed to the harness.
     *
     * @return  string  The emitted plan document.
     *
     * @since   2.0.0
     */
    private function plan(int $seed): string
    {
        $command = sprintf(
            '%s %s --plan --seed=%d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 3) . '/tools/perf-harness.php'),
            $seed,
        );
        $output = shell_exec($command);
        self::assertIsString($output);
        self::assertNotSame('', $output);

        return $output;
    }
}
