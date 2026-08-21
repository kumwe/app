<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Presentation\Asset;

use Kumwe\App\Presentation\Asset\AssetEntry;
use Kumwe\App\Presentation\Asset\ViteAssetManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the server-side Vite resolver implements the initial-document manifest graph exactly.
 *
 * @since  2.0.0
 */
#[CoversClass(ViteAssetManifest::class)]
#[UsesClass(AssetEntry::class)]
final class ViteAssetManifestTest extends TestCase
{
    private string $manifest;

    /**
     * Allocate a manifest path unique to this test process.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->manifest = sys_get_temp_dir() . '/kumwe-vite-manifest-' . bin2hex(random_bytes(8)) . '.json';
    }

    /**
     * Remove the temporary manifest after each proof.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        if (is_file($this->manifest)) {
            unlink($this->manifest);
        }
    }

    /**
     * A checkout with no build retains the explicitly supplied runtime fallbacks.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingManifestUsesOnlyExplicitFallbacks(): void
    {
        $entry = (new ViteAssetManifest($this->manifest))->entry(
            'assets/site/main.ts',
            '/assets/site.css',
            '/assets/site.js',
        );

        self::assertSame(['/assets/site.css'], $entry->stylesheets);
        self::assertSame(['/assets/site.js'], $entry->modules);
    }

    /**
     * Entry CSS leads Vite's dependency-first static closure, duplicates retain first occurrence,
     * and dynamic imports stay out of the initial document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInitialDocumentUsesOwnCssThenOnlyTheStableStaticImportClosure(): void
    {
        $this->writeManifest([
            'assets/site/main.ts' => $this->validEntry([
                'file' => 'js/site.js',
                'css' => ['css/entry.css', 'css/shared.css'],
                'imports' => ['_first.js', '_second.js'],
                'dynamicImports' => ['_dynamic.js'],
            ]),
            '_first.js' => [
                'file' => 'css/first-entry.css',
                'css' => ['css/first.css', 'css/shared.css'],
                'assets' => ['css/first-asset.css', 'fonts/first.woff2'],
                'imports' => ['_leaf.js'],
            ],
            '_leaf.js' => [
                'file' => 'js/leaf.js',
                'src' => '_synthetic-shared.css',
                'css' => ['css/leaf.css'],
            ],
            '_second.js' => [
                'file' => 'js/second.js',
                'css' => ['css/second.css'],
                'imports' => ['_leaf.js'],
            ],
            '_dynamic.js' => [
                'file' => 'js/dynamic.js',
                'css' => ['css/dynamic.css'],
            ],
        ]);

        $entry = (new ViteAssetManifest($this->manifest, '/build/'))->entry(
            'assets/site/main.ts',
            '/assets/site.css',
        );

        self::assertSame([
            '/build/css/entry.css',
            '/build/css/shared.css',
            '/build/css/leaf.css',
            '/build/css/first.css',
            '/build/css/first-entry.css',
            '/build/css/first-asset.css',
            '/build/css/second.css',
        ], $entry->stylesheets);
        self::assertSame(['/build/js/site.js'], $entry->modules);
    }

    /**
     * A missing static or dynamic import is a malformed deployment, even though dynamic CSS is not linked.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingStaticOrDynamicImportFailsClosed(): void
    {
        foreach (['imports' => 'static', 'dynamicImports' => 'dynamic'] as $field => $kind) {
            $this->writeManifest([
                'assets/site/main.ts' => $this->validEntry([
                    'file' => 'js/site.js',
                    $field => ['_missing.js'],
                ]),
            ]);

            try {
                (new ViteAssetManifest($this->manifest))->entry('assets/site/main.ts', '/assets/site.css');
                self::fail($kind . ' missing import must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('missing ' . $kind . ' import _missing.js', $exception->getMessage());
            }
        }
    }

    /**
     * A static import cycle cannot choose an order based on incidental recursion state.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStaticImportCycleFailsClosed(): void
    {
        $this->writeManifest([
            'assets/site/main.ts' => $this->validEntry([
                'file' => 'js/site.js',
                'imports' => ['_cycle.js'],
            ]),
            '_cycle.js' => [
                'file' => 'js/cycle.js',
                'imports' => ['assets/site/main.ts'],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('static import graph contains a cycle');
        (new ViteAssetManifest($this->manifest))->entry('assets/site/main.ts', '/assets/site.css');
    }

    /**
     * List fields and requested-entry metadata retain their exact Vite manifest shapes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedManifestListsOrEntryMetadataFailClosed(): void
    {
        foreach ([
            'css' => ['css' => ['named' => 'css/site.css']],
            'imports' => ['imports' => ['_same.js', '_same.js']],
            'dynamicImports' => ['dynamicImports' => 'not-a-list'],
            'assets' => ['assets' => ['named' => 'css/site.css']],
        ] as $field => $mutation) {
            $this->writeManifest([
                'assets/site/main.ts' => $this->validEntry([
                    'file' => 'js/site.js',
                    ...$mutation,
                ]),
                '_same.js' => ['file' => 'js/same.js'],
            ]);

            try {
                (new ViteAssetManifest($this->manifest))->entry('assets/site/main.ts', '/assets/site.css');
                self::fail($field . ' must be validated.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString($field, $exception->getMessage(), $field);
            }
        }

        foreach ([
            'missing isEntry' => ['file' => 'js/site.js', 'src' => 'assets/site/main.ts'],
            'false isEntry' => ['file' => 'js/site.js', 'src' => 'assets/site/main.ts', 'isEntry' => false],
            'non-boolean isEntry' => ['file' => 'js/site.js', 'src' => 'assets/site/main.ts', 'isEntry' => 1],
            'missing src' => ['file' => 'js/site.js', 'isEntry' => true],
            'mismatched src' => ['file' => 'js/site.js', 'src' => 'assets/other/main.ts', 'isEntry' => true],
            'non-string src' => ['file' => 'js/site.js', 'src' => 1, 'isEntry' => true],
        ] as $kind => $record) {
            $this->writeManifest(['assets/site/main.ts' => $record]);

            try {
                (new ViteAssetManifest($this->manifest))->entry('assets/site/main.ts', '/assets/site.css');
                self::fail($kind . ' must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString(
                    'declared entry with matching source',
                    $exception->getMessage(),
                    $kind,
                );
            }
        }
    }

    /**
     * Module and stylesheet URLs cannot escape or reinterpret the configured public build prefix.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnsafeOrNonCssOutputsFailClosed(): void
    {
        foreach ([
            'module' => ['file' => '../site.js'],
            'platform path' => ['file' => 'js/site.js', 'css' => ['css\\site.css']],
            'non-CSS' => ['file' => 'js/site.js', 'css' => ['js/not-a-style.js']],
        ] as $kind => $record) {
            $this->writeManifest(['assets/site/main.ts' => $this->validEntry($record)]);

            try {
                (new ViteAssetManifest($this->manifest))->entry('assets/site/main.ts', '/assets/site.css');
                self::fail($kind . ' must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertMatchesRegularExpression('/unsafe|non-CSS/', $exception->getMessage(), $kind);
            }
        }
    }

    /**
     * Persist one deterministic manifest fixture.
     *
     * @param   array<string, array<string, mixed>>  $manifest  Keyed Vite records to encode.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function writeManifest(array $manifest): void
    {
        self::assertNotFalse(file_put_contents(
            $this->manifest,
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ));
    }

    /**
     * Add the exact metadata Vite emits for the requested source entry.
     *
     * @param   array<string, mixed>  $record  Entry fields under test.
     *
     * @return  array<string, mixed>  Record with valid entry identity metadata.
     *
     * @since   2.0.0
     */
    private function validEntry(array $record): array
    {
        return [
            'src' => 'assets/site/main.ts',
            'isEntry' => true,
            ...$record,
        ];
    }
}
