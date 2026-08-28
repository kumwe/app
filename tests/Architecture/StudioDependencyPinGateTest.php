<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Holds the Studio dependency-pin gate to the exact-version rule ADR 0007 states.
 *
 * Adoption step 1 in `docs/roadmap/producer-adoption.md` says a non-exact specifier for
 * `kumwe/producer` or any `@kumwe/studio` artifact fails the build, and finding V2-STU-002 names
 * the hole that rule closes: a range takes a contract change silently while Studio is pre-release.
 * `tools/verify-studio-dependencies.php` is that gate, so this slice proves the gate itself: it
 * passes the repository as committed, it refuses a synthetic range on either manifest before the
 * Producer pin ever lands, it accepts the exact pin the adoption sequence will introduce, and it
 * refuses a `file:` reference that escapes the digest-verified vendored tarball directory. It also
 * pins the wiring — the gate runs in `composer qa` beside `studio:corpus` — because an unwired
 * gate guards nothing.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class StudioDependencyPinGateTest extends TestCase
{
    /**
     * Repository root, resolved once per test.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Synthetic manifests written by a test, removed again after it.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $temporary = [];

    /**
     * Resolve the repository root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * Remove every synthetic manifest a test wrote.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
        $this->temporary = [];
    }

    /**
     * The committed manifests satisfy the gate: every guarded dependency is exactly pinned.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCommittedDependencySetSatisfiesTheGate(): void
    {
        $result = $this->execute([]);

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('Kumwe studio dependencies verified', $result['output']);
    }

    /**
     * A Composer range for kumwe/producer fails the gate and names the offending specifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAComposerRangeForProducerFailsTheGate(): void
    {
        $result = $this->execute([
            '--composer=' . $this->write(['require' => ['kumwe/producer' => '^0.1']]),
            '--package=' . $this->write([]),
        ]);

        self::assertSame(1, $result['status'], $result['output']);
        self::assertStringContainsString('kumwe/producer as "^0.1"', $result['output']);
        self::assertStringContainsString('ADR 0007', $result['output']);
    }

    /**
     * An npm range for an @kumwe/studio* package fails the gate in any dependency section.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnNpmRangeForAStudioPackageFailsTheGate(): void
    {
        $result = $this->execute([
            '--composer=' . $this->write([]),
            '--package=' . $this->write(['devDependencies' => ['@kumwe/studio-core' => '~0.1.0']]),
        ]);

        self::assertSame(1, $result['status'], $result['output']);
        self::assertStringContainsString('@kumwe/studio-core as "~0.1.0"', $result['output']);
    }

    /**
     * The exact Producer pin the adoption sequence introduces passes before the package publishes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExactProducerPinPassesTheGate(): void
    {
        $result = $this->execute([
            '--composer=' . $this->write(['require' => ['kumwe/producer' => '0.1.0']]),
            '--package=' . $this->write([]),
        ]);

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('1 exact pin(s)', $result['output']);
    }

    /**
     * A file: reference outside the digest-verified vendored tarball directory fails the gate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAForeignFileReferenceFailsTheGate(): void
    {
        $result = $this->execute([
            '--composer=' . $this->write([]),
            '--package=' . $this->write([
                'dependencies' => ['@kumwe/studio' => 'file:../studio/packages/kumwe-studio-0.1.0.tgz'],
            ]),
        ]);

        self::assertSame(1, $result['status'], $result['output']);
        self::assertStringContainsString('@kumwe/studio as "file:../studio', $result['output']);
    }

    /**
     * The gate is wired where studio:corpus already runs, so qa cannot pass without it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateRunsInTheLocalLaneBesideTheCorpusGate(): void
    {
        $composer = json_decode((string) file_get_contents($this->root . '/composer.json'), true);
        self::assertIsArray($composer);
        $scripts = $composer['scripts'] ?? null;
        self::assertIsArray($scripts);
        self::assertSame('php tools/verify-studio-dependencies.php', $scripts['studio:dependencies'] ?? null);

        $qa = $scripts['qa'] ?? null;
        self::assertIsArray($qa);
        $corpus = array_search('@studio:corpus', $qa, true);
        self::assertIsInt($corpus, 'The corpus gate anchors the Studio slice of the local lane.');
        self::assertContains('@studio:dependencies', $qa, 'The pin gate must run in composer qa.');
    }

    /**
     * Run the dependency gate and capture its verdict.
     *
     * @param   list<string>  $arguments  Tool arguments.
     *
     * @return  array{status: int, output: string}  Exit status and combined output.
     *
     * @since   2.0.0
     */
    private function execute(array $arguments): array
    {
        $command = sprintf(
            '%s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->root . '/tools/verify-studio-dependencies.php'),
        );
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $lines = [];
        $status = 0;
        exec($command . ' 2>&1', $lines, $status);

        return ['status' => $status, 'output' => implode("\n", $lines)];
    }

    /**
     * Write one synthetic manifest for the gate to scan.
     *
     * @param   array<string, array<string, string>>  $manifest  Manifest content.
     *
     * @return  string  Absolute path of the written manifest.
     *
     * @since   2.0.0
     */
    private function write(array $manifest): string
    {
        $path = sys_get_temp_dir() . '/kumwe-studio-pins-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, json_encode($manifest === [] ? new stdClass() : $manifest));
        $this->temporary[] = $path;

        return $path;
    }
}
