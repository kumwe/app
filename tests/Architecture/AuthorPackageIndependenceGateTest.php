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
 * Proves every artifact an extension author copies or installs is independent of the App host.
 *
 * The contract ledgers, retained generation source, scaffold output, and first-party examples form one
 * author-facing surface. A private host import in any one of them would recreate the remapping layer this
 * extraction removes, so the dependency-free verifier has an accepted fixture and a negative fixture for
 * every category it scans.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class AuthorPackageIndependenceGateTest extends TestCase
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
     * The installed SDK and the installable examples publish no host-private namespaces.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCommittedAuthorPackagesAreHostIndependent(): void
    {
        $result = $this->execute([]);

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('Author-package independence verified', $result['output']);
        self::assertStringContainsString('no Kumwe\App\ imports', $result['output']);
    }

    /**
     * A complete canonical package tree passes without needing an alias or a host compatibility fixture.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalSyntheticAuthorPackagesPass(): void
    {
        $tree = $this->fixture();
        try {
            $result = $this->execute([
                '--examples=' . $tree . '/examples',
                '--sdk-resources=' . $tree . '/sdk-resources',
            ]);
        } finally {
            $this->removeDirectory($tree);
        }

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('5 files', $result['output']);
    }

    /**
     * Every author-facing category rejects both source-form and JSON-escaped private namespaces.
     *
     * @param   string  $relative  Fixture-relative artifact to corrupt.
     * @param   string  $contents  Private source or evidence to write.
     * @param   string  $display   Stable category path expected in the diagnostic.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('privateNamespaceSources')]
    public function testEveryAuthorSourceCategoryRejectsPrivateAppNamespaces(
        string $relative,
        string $contents,
        string $display,
    ): void {
        $tree = $this->fixture();
        self::assertNotFalse(file_put_contents($tree . '/' . $relative, $contents));

        try {
            $result = $this->execute([
                '--examples=' . $tree . '/examples',
                '--sdk-resources=' . $tree . '/sdk-resources',
            ]);
        } finally {
            $this->removeDirectory($tree);
        }

        self::assertSame(1, $result['status'], $result['output']);
        self::assertStringContainsString($display, $result['output']);
        self::assertStringContainsString('private Kumwe\App\ namespace', $result['output']);
    }

    /**
     * Supply one private import for every independently scanned author-source category.
     *
     * @return  iterable<string, array{string, string, string}>  Corrupted file, contents, and diagnostic.
     *
     * @since   2.0.0
     */
    public static function privateNamespaceSources(): iterable
    {
        yield 'installable example source' => [
            'examples/acme/src/Provider.php',
            "<?php\n\nuse Kumwe\\App\\Extension\\PrivateProvider;\n",
            'examples/extensions/acme/src/Provider.php',
        ];
        yield 'SDK classification ledger' => [
            'sdk-resources/contract/classification.json',
            '{"symbol":"Kumwe\\\\App\\\\Extension\\\\PrivateProvider"}',
            'sdk-resources/contract/classification.json',
        ];
        yield 'SDK generations ledger' => [
            'sdk-resources/contract/generations.json',
            '{"symbol":"Kumwe\\\\App\\\\Extension\\\\PrivateProvider"}',
            'sdk-resources/contract/generations.json',
        ];
        yield 'retained generation source' => [
            'sdk-resources/fixtures/generations/manifest-1/src/Provider.php',
            "<?php\n\nuse Kumwe\\App\\Extension\\PrivateProvider;\n",
            'sdk-resources/fixtures/generations/manifest-1/src/Provider.php',
        ];
        yield 'generated scaffold source' => [
            'sdk-resources/extension-scaffold/acme/src/Provider.php.tpl',
            "<?php\n\nuse Kumwe\\App\\Extension\\PrivateProvider;\n",
            'sdk-resources/extension-scaffold/acme/src/Provider.php.tpl',
        ];
    }

    /**
     * Composer cannot restore host coupling under a package coordinate instead of a PHP namespace.
     *
     * @param   string  $relative  Fixture-relative Composer manifest or template.
     * @param   string  $contents  Dependency declaration to write.
     * @param   string  $display   Stable category path expected in the diagnostic.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('privateComposerDependencies')]
    public function testAuthorComposerDependenciesCannotPullTheAppHost(
        string $relative,
        string $contents,
        string $display,
    ): void {
        $tree = $this->fixture();
        self::assertNotFalse(file_put_contents($tree . '/' . $relative, $contents));

        try {
            $result = $this->execute([
                '--examples=' . $tree . '/examples',
                '--sdk-resources=' . $tree . '/sdk-resources',
            ]);
        } finally {
            $this->removeDirectory($tree);
        }

        self::assertSame(1, $result['status'], $result['output']);
        self::assertStringContainsString($display, $result['output']);
        self::assertStringContainsString('forbidden kumwe/app Composer dependency', $result['output']);
        self::assertStringNotContainsString('private Kumwe\App\ namespace', $result['output']);
    }

    /**
     * Exercise both valid JSON and textual scaffold-template dependency declarations case-insensitively.
     *
     * @return  iterable<string, array{string, string, string}>  Manifest, contents, and diagnostic path.
     *
     * @since   2.0.0
     */
    public static function privateComposerDependencies(): iterable
    {
        yield 'example Composer JSON' => [
            'examples/acme/composer.json',
            '{"require":{"Kumwe/App":"2.0.0"},"description":"independent example"}',
            'examples/extensions/acme/composer.json',
        ];
        yield 'scaffold Composer text template' => [
            'sdk-resources/extension-scaffold/acme/composer.json.tpl',
            "require-dev = KUMWE/APP ^2.0\n",
            'sdk-resources/extension-scaffold/acme/composer.json.tpl',
        ];
    }

    /**
     * An explanatory mention outside a dependency section is not mistaken for a Composer link.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testComposerDescriptionProseDoesNotCreateAFalseDependency(): void
    {
        $tree = $this->fixture();
        self::assertNotFalse(file_put_contents(
            $tree . '/examples/acme/composer.json',
            '{"description":"This package replaces neither kumwe/app nor another host.","require":{"php":"^8.5"}}',
        ));

        try {
            $result = $this->execute([
                '--examples=' . $tree . '/examples',
                '--sdk-resources=' . $tree . '/sdk-resources',
            ]);
        } finally {
            $this->removeDirectory($tree);
        }

        self::assertSame(0, $result['status'], $result['output']);
    }

    /**
     * Missing retained source is a failure rather than an empty tree that passes the namespace search.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARequiredAuthorSourceTreeCannotBeSilentlySkipped(): void
    {
        $tree = $this->fixture();
        $generation = $tree . '/sdk-resources/fixtures/generations/manifest-1/src/Provider.php';
        self::assertTrue(unlink($generation));
        self::assertTrue(rmdir(dirname($generation)));
        self::assertTrue(rmdir(dirname($generation, 2)));

        try {
            $result = $this->execute([
                '--examples=' . $tree . '/examples',
                '--sdk-resources=' . $tree . '/sdk-resources',
            ]);
        } finally {
            $this->removeDirectory($tree);
        }

        self::assertSame(1, $result['status'], $result['output']);
        self::assertStringContainsString(
            'sdk-resources/fixtures/generations contains no package files to verify',
            $result['output'],
        );
    }

    /**
     * Composer, the quality contract, and both merge preflights run the same dedicated verifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheIndependenceGateIsWiredIntoEveryQualityEntryPoint(): void
    {
        $composer = $this->document($this->root . '/composer.json');
        self::assertSame(
            'php tools/verify-author-package-independence.php',
            $composer['scripts']['extension:independence'] ?? null,
        );
        self::assertContains('@extension:independence', $composer['scripts']['qa'] ?? []);

        $contract = $this->document($this->root . '/docs/quality/contract.json');
        $matches = array_values(array_filter(
            $contract['checks'] ?? [],
            static fn (mixed $check): bool => is_array($check)
                && ($check['id'] ?? null) === 'author-package-independence',
        ));
        self::assertCount(1, $matches);
        self::assertSame('extension:independence', $matches[0]['composer_script'] ?? null);
        self::assertTrue($matches[0]['in_qa'] ?? false);

        $workflow = file_get_contents($this->root . '/.github/workflows/ci.yml');
        self::assertIsString($workflow);
        self::assertGreaterThanOrEqual(2, substr_count($workflow, 'composer extension:independence'));
    }

    /**
     * Create one complete, canonical author-package fixture.
     *
     * @return  string  Absolute fixture root.
     *
     * @since   2.0.0
     */
    private function fixture(): string
    {
        $tree = sys_get_temp_dir() . '/kumwe-author-independence-' . bin2hex(random_bytes(8));
        $files = [
            'examples/acme/src/Provider.php' => "<?php\n\nuse Kumwe\\Extension\\Spi\\Application\\Provider;\n",
            'sdk-resources/contract/classification.json' => '{"symbols":["Kumwe\\\\Extension\\\\Manifest"]}',
            'sdk-resources/contract/generations.json' => '{"generations":["manifest-1"]}',
            'sdk-resources/fixtures/generations/manifest-1/src/Provider.php'
                => "<?php\n\nuse Kumwe\\Extension\\Spi\\Application\\Provider;\n",
            'sdk-resources/extension-scaffold/acme/src/Provider.php.tpl'
                => "<?php\n\nuse Kumwe\\Extension\\Spi\\Application\\Provider;\n",
        ];
        foreach ($files as $relative => $contents) {
            $directory = dirname($tree . '/' . $relative);
            if (!is_dir($directory)) {
                self::assertTrue(mkdir($directory, 0o700, true));
            }
            self::assertNotFalse(file_put_contents($tree . '/' . $relative, $contents));
        }

        return $tree;
    }

    /**
     * Execute the dependency-free author-package verifier.
     *
     * @param   list<string>  $arguments  Override arguments for isolated fixtures.
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
            escapeshellarg($this->root . '/tools/verify-author-package-independence.php'),
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
     * Decode one repository JSON document.
     *
     * @param   string  $path  Absolute document path.
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
