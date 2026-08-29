<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Proves canonical first-party package identities cannot be remapped at runtime.
 *
 * App's tracked source, resources, tests, and examples and every installed extracted package source/resource tree
 * share one zero-baseline gate. PHP tokens distinguish executable alias calls from explanatory strings;
 * package resources receive the equivalent textual refusal.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class PackageBoundaryGateTest extends TestCase
{
    /**
     * Repository root.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

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
     * The tracked App and all installed first-party package trees contain no runtime remapping.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTrackedAndInstalledPackageBoundariesContainNoAliases(): void
    {
        $result = $this->execute([]);

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('Package boundaries verified', $result['output']);
        self::assertStringContainsString('no class aliases', $result['output']);
    }

    /**
     * Both PHP source and an App/package resource reject a runtime alias declaration.
     *
     * @param   string  $relative  Fixture-relative path.
     * @param   string  $contents  Alias declaration source.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('aliasSources')]
    public function testRuntimeAliasesFailEveryBoundaryKind(string $relative, string $contents): void
    {
        $tree = $this->fixture($relative, $contents);
        try {
            $result = $this->execute(['--root=' . $tree]);
        } finally {
            $this->removeDirectory($tree);
        }

        self::assertSame(1, $result['status'], $result['output']);
        self::assertStringContainsString($relative, $result['output']);
        self::assertStringContainsString('calls class_' . 'alias()', $result['output']);
        self::assertStringContainsString('cannot be remapped', $result['output']);
    }

    /**
     * Supply executable PHP and textual resource alias declarations without embedding a live call here.
     *
     * @return  iterable<string, array{string, string}>  Fixture path and source.
     *
     * @since   2.0.0
     */
    public static function aliasSources(): iterable
    {
        yield 'PHP source' => [
            'src/CompatibilityAlias.php',
            "<?php\n\nclass_" . "alias(SourceType::class, HistoricalType::class);\n",
        ];
        yield 'App/package resource' => [
            'resources/generated-source.txt',
            "class_" . "alias(SourceType::class, HistoricalType::class);\n",
        ];
    }

    /**
     * Comments and string fixtures can describe the forbidden mechanism without executing it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPhpCommentsAndStringsAreNotFalseAliasCalls(): void
    {
        $needle = 'class_' . 'alias(SourceType::class, HistoricalType::class);';
        $tree = $this->fixture(
            'tests/Explanation.php',
            "<?php\n\n// " . $needle . "\n\n\$example = '" . $needle . "';\n",
        );
        try {
            $result = $this->execute(['--root=' . $tree]);
        } finally {
            $this->removeDirectory($tree);
        }

        self::assertSame(0, $result['status'], $result['output']);
    }

    /**
     * The six retired StudioProfile aliases must remain absent from the tracked repository authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHistoricalStudioProfileAliasShimsRemainUntracked(): void
    {
        $directory = 'src/Extension/Domain/Internal/StudioProfile/';
        $paths = [];
        foreach (
            [
                'CanonicalJson.php',
                'CanonicalJsonRejected.php',
                'SchemaInstanceDiagnostic.php',
                'SchemaProfileRejected.php',
                'SchemaPropertyProfile.php',
                'SchemaPropertyValidator.php',
            ] as $file
        ) {
            $paths[] = $directory . $file;
        }

        $command = sprintf(
            'git -C %s ls-files -- %s',
            escapeshellarg($this->root),
            implode(' ', array_map('escapeshellarg', $paths)),
        );
        $tracked = [];
        $status = 0;
        exec($command . ' 2>&1', $tracked, $status);

        self::assertSame(0, $status, implode("\n", $tracked));
        self::assertSame([], $tracked, 'Historical StudioProfile aliases must not return to the Git index.');
    }

    /**
     * Producer-owned Studio contract authorities must not be copied back into App.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProducerOwnedStudioContractMirrorsRemainUntracked(): void
    {
        $paths = [
            'src/Studio/Domain/Contract/',
            'resources/studio-contract/protocol/',
            'tests/Fixtures/Studio/testkit/',
        ];
        $command = sprintf(
            'git -C %s ls-files -- %s',
            escapeshellarg($this->root),
            implode(' ', array_map('escapeshellarg', $paths)),
        );
        $tracked = [];
        $status = 0;
        exec($command . ' 2>&1', $tracked, $status);

        self::assertSame(0, $status, implode("\n", $tracked));
        self::assertSame(
            [],
            $tracked,
            'Producer-owned Studio contract source, protocol, and testkit mirrors must not return to App.',
        );
    }

    /**
     * The dedicated verifier is part of architecture policy and the machine-readable quality contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPackageBoundaryVerificationRunsThroughArchitecturePolicy(): void
    {
        $composer = $this->document($this->root . '/composer.json');
        self::assertSame(
            'php tools/verify-package-boundaries.php',
            $composer['scripts']['architecture:boundaries'] ?? null,
        );
        $policy = file_get_contents($this->root . '/tools/verify-policy.sh');
        self::assertIsString($policy);
        self::assertStringContainsString('tools/verify-package-boundaries.php', $policy);

        $contract = $this->document($this->root . '/docs/quality/contract.json');
        $checks = array_values(array_filter(
            $contract['checks'] ?? [],
            static fn (mixed $check): bool => is_array($check)
                && ($check['id'] ?? null) === 'canonical-package-boundaries',
        ));
        self::assertCount(1, $checks);
        self::assertSame('architecture:boundaries', $checks[0]['composer_script'] ?? null);
        self::assertSame('architecture-policy', $checks[0]['invoked_by'] ?? null);
        self::assertFalse($checks[0]['in_qa'] ?? true);
    }

    /**
     * Write one isolated boundary fixture.
     *
     * @param   string  $relative  Fixture-relative path.
     * @param   string  $contents  Complete file bytes.
     *
     * @return  string  Absolute fixture root.
     *
     * @since   2.0.0
     */
    private function fixture(string $relative, string $contents): string
    {
        $tree = sys_get_temp_dir() . '/kumwe-package-boundary-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tree . '/' . dirname($relative), 0o700, true));
        self::assertNotFalse(file_put_contents($tree . '/' . $relative, $contents));

        return $tree;
    }

    /**
     * Execute the package-boundary verifier.
     *
     * @param   list<string>  $arguments  Optional isolated roots.
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
            escapeshellarg($this->root . '/tools/verify-package-boundaries.php'),
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
     * Decode one repository JSON object.
     *
     * @param   string  $path  Document path.
     *
     * @return  array<string, mixed>  Decoded object.
     *
     * @since   2.0.0
     */
    private function document(string $path): array
    {
        $bytes = file_get_contents($path);
        self::assertIsString($bytes, $path);
        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $path);

        return $decoded;
    }

    /**
     * Remove an isolated fixture tree.
     *
     * @param   string  $directory  Absolute tree path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
