<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Proves that the extension contract has one package owner rather than a translated App copy.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class ExtensionContractGateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /** @since 2.0.0 */
    public function testTheSdkDependencyOwnsEveryContractArtifact(): void
    {
        self::assertFileDoesNotExist($this->root . '/docs/extension-contract/classification.json');
        self::assertFileDoesNotExist($this->root . '/docs/extension-contract/generations.json');
        self::assertDirectoryDoesNotExist($this->root . '/tests/Fixtures/ExtensionApi');

        $readme = file_get_contents($this->root . '/docs/extension-contract/README.md');
        self::assertIsString($readme);
        self::assertStringContainsString('kumwe/extension-sdk', $readme);
        self::assertStringContainsString('sole authority', $readme);
        self::assertStringContainsString('no second App-owned public-API ledger', file_get_contents(
            $this->root . '/tools/verify-extension-contract.php',
        ) ?: '');
    }

    /** @since 2.0.0 */
    public function testTheInstalledSdkResourceTreeMatchesItsPackagePin(): void
    {
        $resources = $this->root . '/vendor/kumwe/extension-sdk/resources';
        $pin = $this->document($resources . '/PIN.json');
        self::assertSame('kumwe-extension-sdk-resource-pin-v1', $pin['format'] ?? null);

        $expected = [];
        foreach ($pin['files'] ?? [] as $entry) {
            self::assertIsArray($entry);
            $file = $entry['file'] ?? null;
            $digest = $entry['sha256'] ?? null;
            self::assertIsString($file);
            self::assertMatchesRegularExpression(
                '#^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._/-]+$#D',
                $file,
            );
            self::assertIsString($digest);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $digest);
            self::assertArrayNotHasKey($file, $expected);
            $expected[$file] = $digest;
            self::assertSame($digest, hash_file('sha256', $resources . '/' . $file));
        }

        $actual = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resources, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || !$entry->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($resources) + 1));
            if ($relative !== 'PIN.json') {
                $actual[] = $relative;
            }
        }
        sort($actual, SORT_STRING);
        $pinned = array_keys($expected);
        sort($pinned, SORT_STRING);
        self::assertSame($pinned, $actual);
    }

    /** @since 2.0.0 */
    public function testCanonicalSdkContractArtifactsPublishNoHistoricalAppTypes(): void
    {
        $resources = $this->root . '/vendor/kumwe/extension-sdk/resources';
        foreach (['contract/classification.json', 'contract/generations.json'] as $relative) {
            $bytes = file_get_contents($resources . '/' . $relative);
            self::assertIsString($bytes);
            self::assertStringNotContainsString('Kumwe\\\\App\\\\', $bytes, $relative);
        }
    }

    /** @since 2.0.0 */
    public function testComposerRunsThePackageOwnedContractGate(): void
    {
        $composer = $this->document($this->root . '/composer.json');
        self::assertArrayHasKey('kumwe/extension-sdk', $composer['require'] ?? []);
        self::assertSame(
            'php tools/verify-extension-contract.php',
            $composer['scripts']['extension:contract'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     * @since 2.0.0
     */
    private function document(string $path): array
    {
        $bytes = file_get_contents($path);
        self::assertIsString($bytes, $path);
        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $path);

        return $decoded;
    }
}
