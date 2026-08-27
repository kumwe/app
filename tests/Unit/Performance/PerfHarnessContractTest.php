<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Performance;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

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
        foreach (['report', 'breakpoint'] as $section) {
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
}
