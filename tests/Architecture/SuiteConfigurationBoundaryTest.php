<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversNothing]
/**
 * Holds every integration and functional test to the configuration boundary the application obeys.
 *
 * `Environment` is the one first-party class permitted to read the process environment or the dotenv
 * file, and the container's `ApplicationConfiguration` is what everything else receives. An integration
 * test that reads `getenv('REDIS_NAMESPACE')` or `getenv('DB_HOST')` instead answers `false` whenever a
 * deployment configures through `.env` — which is how four tests silently fell back to shared defaults:
 * one shared Redis namespace between installations, a relay pointed at a database nothing was using, a
 * fingerprint keyed on a secret the container never held, and a matrix test rebuilt on SQLite while the
 * suite ran on the configured server (V2-QA-009), and how the kernel case defaulted to a PostgreSQL that
 * need not exist (V2-QA-011). Scanning one tree left the pattern free to live in the next one, so both
 * are held: no test in either may read a named environment variable raw, and the zero-argument
 * forwarding read is allowed only where the allowlist records why.
 *
 * @since  2.0.0
 */
final class SuiteConfigurationBoundaryTest extends TestCase
{
    /**
     * The zero-argument `getenv()` reads that are allowed to remain, each with the reason it is one.
     *
     * An entry may only cover forwarding the whole parent environment verbatim to a spawned process —
     * never reading a value out of it in-process — and the test below fails an entry whose file carries
     * a named read anyway, so the allowlist cannot quietly widen into an exemption.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const ALLOWED_FORWARDING_READS = [
        'tests/Integration/Automation/DatabaseLossRecoveryIntegrationTest.php'
            => 'Forwards the whole parent environment verbatim to the spawned killable worker, so a '
            . 'deployment that configures by real process variables keeps working in the child process; '
            . 'the relay override comes from ApplicationConfiguration and nothing is read in-process.',
    ];

    /**
     * No integration or functional test may read a named environment variable with raw `getenv()`.
     *
     * Connection and namespace configuration reaches a test through the container's
     * `ApplicationConfiguration`, or through `Environment::fromGlobals()` where no container is booted
     * — both of which see the dotenv file. A raw named read sees only the process environment, and its
     * fallback value is what one installation silently shares with another, or a server nothing runs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoSuiteTestReadsANamedEnvironmentVariableRaw(): void
    {
        $violations = [];
        foreach ($this->suiteFiles() as $relative => $contents) {
            foreach ($this->readsOf($contents) as $line => $argument) {
                if ($argument === '') {
                    continue;
                }
                $violations[] = sprintf('%s:%d reads getenv(%s)', $relative, $line, $argument);
            }
        }

        sort($violations, SORT_STRING);
        self::assertSame(
            [],
            $violations,
            "Suite tests read configuration through ApplicationConfiguration or Environment, "
                . "never with raw getenv():\n - " . implode("\n - ", $violations),
        );
    }

    /**
     * The zero-argument forwarding read is allowed exactly where the allowlist records why, and no wider.
     *
     * Both directions are held: a bare `getenv()` outside the allowlist fails, and an allowlist entry
     * whose file no longer carries one fails as stale — the list only ever shrinks.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheForwardingAllowlistIsExactAndOnlyEverShrinks(): void
    {
        $found = [];
        foreach ($this->suiteFiles() as $relative => $contents) {
            foreach ($this->readsOf($contents) as $line => $argument) {
                if ($argument !== '') {
                    continue;
                }
                $found[$relative] = $line;
                self::assertArrayHasKey(
                    $relative,
                    self::ALLOWED_FORWARDING_READS,
                    sprintf(
                        '%s:%d reads the whole environment with getenv(); forwarding reads must be '
                            . 'allowlisted here with the reason they are forwarding and nothing more.',
                        $relative,
                        $line,
                    ),
                );
            }
        }

        foreach (self::ALLOWED_FORWARDING_READS as $relative => $reason) {
            self::assertNotSame('', trim($reason), sprintf('%s needs its reason stated.', $relative));
            self::assertArrayHasKey(
                $relative,
                $found,
                sprintf('%s no longer carries a forwarding read; delete its stale allowlist entry.', $relative),
            );
        }
    }

    /**
     * Read every integration and functional test file, keyed by repository-relative path.
     *
     * @return  array<string, string>  File contents by path, sorted by path.
     *
     * @since   2.0.0
     */
    private function suiteFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (['tests/Integration', 'tests/Functional'] as $tree) {
            $walk = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $tree, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($walk as $file) {
                self::assertInstanceOf(SplFileInfo::class, $file);
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                self::assertIsString($contents);
                $files[substr($file->getPathname(), strlen($root) + 1)] = $contents;
            }
        }
        ksort($files, SORT_STRING);
        self::assertNotSame([], $files, 'Both trees are expected to hold test files.');

        return $files;
    }

    /**
     * Find every `getenv` call in one file, keyed by line number, with its raw argument text.
     *
     * Comment lines are skipped so a docblock explaining why a raw read was removed does not read as
     * one; only lines that are code are held to the boundary.
     *
     * @param   string  $contents  The file's source.
     *
     * @return  array<int, string>  Trimmed argument text by one-based line number; empty for `getenv()`.
     *
     * @since   2.0.0
     */
    private function readsOf(string $contents): array
    {
        $reads = [];
        foreach (explode("\n", $contents) as $index => $line) {
            $leading = ltrim($line);
            if (str_starts_with($leading, '*') || str_starts_with($leading, '//') || str_starts_with($leading, '/*')) {
                continue;
            }
            if (preg_match_all('/\bgetenv\s*\(\s*([^)]*)\)/', $line, $matches) < 1) {
                continue;
            }
            foreach ($matches[1] as $argument) {
                $reads[$index + 1] = trim($argument);
            }
        }

        return $reads;
    }
}
