<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Demo\Infrastructure;

use FilesystemIterator;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Demo\Infrastructure\DemoProfileExporter;
use Kumwe\App\Demo\Infrastructure\FilesystemDemoManifestCatalog;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Proves a live site round-trips through the content exporter into a catalog-valid package.
 *
 * @since  2.0.0
 */
#[CoversClass(DemoProfileExporter::class)]
#[UsesClass(FilesystemDemoManifestCatalog::class)]
final class DemoProfileExporterTest extends TestCase
{
    /**
     * Export a real page, menu, and presentation-bound item and re-validate the written package.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExportedContentManifestDeclaresLiveResourcesAndValidates(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $content = $container->get(ContentService::class);
        $navigation = $container->get(NavigationService::class);
        $exporter = $container->get(DemoProfileExporter::class);
        self::assertInstanceOf(ContentService::class, $content);
        self::assertInstanceOf(NavigationService::class, $navigation);
        self::assertInstanceOf(DemoProfileExporter::class, $exporter);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $page = $content->create(
            $context,
            'Export test page ' . $suffix,
            'export-test-page-' . $suffix,
            ['body' => '<p>Exported body.</p>'],
        );
        $menu = $navigation->createMenu($context, 'export_test_' . $suffix, 'Export test menu ' . $suffix);
        $item = $navigation->createItem(
            $context,
            $menu->id,
            null,
            'Export test item',
            'export-test-item-' . $suffix,
            0,
            'content',
            $page->entry->id(),
            null,
            'landing',
            'aurora',
        );

        $profile = 'export-test-' . $suffix;
        $directory = sys_get_temp_dir() . '/kumwe-export-content-' . $suffix;
        try {
            $manifest = $exporter->contentManifest($context, $profile);
            $checksums = $exporter->writePackage($directory, $profile, [
                sprintf('content/%s.json', $profile) => $manifest,
            ]);
            $verified = new FilesystemDemoManifestCatalog($directory)->content($profile);

            self::assertSame($checksums[sprintf('content/%s.json', $profile)], $verified['checksum']);
            $declaredPage = null;
            self::assertIsArray($verified['manifest']['content']);
            foreach ($verified['manifest']['content'] as $declaration) {
                self::assertIsArray($declaration);
                if (($declaration['resource_id'] ?? null) === $page->entry->id()) {
                    $declaredPage = $declaration;
                }
            }
            self::assertIsArray($declaredPage);
            self::assertSame('Export test page ' . $suffix, $declaredPage['title']);
            self::assertSame('export-test-page-' . $suffix, $declaredPage['slug']);

            $declaredItem = null;
            self::assertIsArray($verified['manifest']['menus']);
            foreach ($verified['manifest']['menus'] as $declaredMenu) {
                self::assertIsArray($declaredMenu);
                if (($declaredMenu['resource_id'] ?? null) !== $menu->id) {
                    continue;
                }
                self::assertIsArray($declaredMenu['items']);
                foreach ($declaredMenu['items'] as $candidate) {
                    self::assertIsArray($candidate);
                    if (($candidate['resource_id'] ?? null) === $item->id) {
                        $declaredItem = $candidate;
                    }
                }
            }
            self::assertIsArray($declaredItem);
            self::assertSame('landing', $declaredItem['template']);
            self::assertSame('aurora', $declaredItem['color_scheme']);
            self::assertIsString($declaredItem['content_fixture_key']);
            self::assertSame($declaredItem['content_fixture_key'], $declaredPage['fixture_key']);
        } finally {
            $this->removeTree($directory);
        }
    }

    /**
     * Remove one exported package tree created below the system temporary directory.
     *
     * @param   string  $directory  Absolute package root to remove.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
