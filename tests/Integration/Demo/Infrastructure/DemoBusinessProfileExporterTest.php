<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Demo\Infrastructure;

use FilesystemIterator;
use Kumwe\Extension\Spi\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\Demo\Infrastructure\DemoBusinessProfileExporter;
use Kumwe\App\Demo\Infrastructure\DemoProfileExporter;
use Kumwe\App\Demo\Infrastructure\FilesystemDemoManifestCatalog;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Proves a live business dataset round-trips through the exporter into a catalog-valid package.
 *
 * @since  2.0.0
 */
#[CoversClass(DemoBusinessProfileExporter::class)]
#[UsesClass(DemoProfileExporter::class)]
#[UsesClass(FilesystemDemoManifestCatalog::class)]
final class DemoBusinessProfileExporterTest extends TestCase
{
    /**
     * Export a freshly authored definition and record and re-validate the written business package.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExportedBusinessDatasetDeclaresLiveResourcesAndValidates(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        $exporter = $container->get(DemoBusinessProfileExporter::class);
        $writer = $container->get(DemoProfileExporter::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        self::assertInstanceOf(DemoBusinessProfileExporter::class, $exporter);
        self::assertInstanceOf(DemoProfileExporter::class, $writer);

        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $definitionId = Uuid::uuid7()->toString();
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document($suffix, $definitionId),
        );
        $recordId = Uuid::uuid7()->toString();
        $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            NeutralBusinessFixture::recordValues('Export test record ' . $suffix),
            IdempotencyKey::fromString('demo-export-test:create:' . $suffix),
            recordId: $recordId,
        ));

        $profile = 'export-test-' . $suffix;
        $documents = $exporter->documents($context, $profile);

        $declared = null;
        self::assertIsArray($documents['profile']['installation_order']);
        foreach ($documents['profile']['installation_order'] as $entry) {
            self::assertIsArray($entry);
            if (($entry['id'] ?? null) === $definitionId) {
                $declared = $entry;
            }
        }
        self::assertIsArray($declared);
        self::assertSame($definition->handle, $declared['handle']);
        self::assertSame('administration', $declared['record_access']);
        self::assertIsString($declared['file']);
        self::assertArrayHasKey($declared['file'], $documents['definitions']);
        self::assertSame($definition->toArray(), $documents['definitions'][$declared['file']]);

        $declaredRecord = null;
        self::assertIsArray($documents['records']['records']);
        foreach ($documents['records']['records'] as $candidate) {
            self::assertIsArray($candidate);
            if (($candidate['record_id'] ?? null) === $recordId) {
                $declaredRecord = $candidate;
            }
        }
        self::assertIsArray($declaredRecord);
        self::assertSame($definition->handle, $declaredRecord['definition']);
        self::assertIsArray($declaredRecord['values']);
        self::assertSame('Export test record ' . $suffix, $declaredRecord['values']['name']);
        self::assertArrayNotHasKey('credential', $declaredRecord['values']);

        $expected = $documents['records']['expected'];
        self::assertIsArray($expected);
        self::assertSame(count($documents['records']['records']), $expected['record_count']);
        self::assertIsArray($documents['records']['relations']);
        self::assertSame(count($documents['records']['relations']), $expected['relation_count']);
        self::assertIsArray($documents['records']['actions']);
        self::assertSame(count($documents['records']['actions']), $expected['action_count']);
        self::assertIsArray($documents['records']['archives']);
        self::assertSame(count($documents['records']['archives']), $expected['archive_count']);

        $directory = sys_get_temp_dir() . '/kumwe-export-business-' . $suffix;
        try {
            // The shared integration database accumulates definitions from the whole suite, so the
            // written package is filtered to this test's definition: the catalog's per-profile
            // envelope (64 definitions, 2 MB documents) stays honest no matter how full the suite
            // database is, while the in-memory assertions above still cover the full-system export.
            $filterEntries = static function (mixed $entries, string $handle): array {
                self::assertIsArray($entries);
                $kept = [];
                foreach ($entries as $entry) {
                    if (is_array($entry) && ($entry['definition'] ?? null) === $handle) {
                        $kept[] = $entry;
                    }
                }

                return $kept;
            };
            $profileDocument = $documents['profile'];
            self::assertIsArray($profileDocument);
            $declaredEntry = $declared;
            $declaredEntry['depends_on'] = [];
            $profileDocument['installation_order'] = [$declaredEntry];
            $recordsDocument = $documents['records'];
            self::assertIsArray($recordsDocument);
            $recordsDocument['records'] = $filterEntries($recordsDocument['records'], $definition->handle);
            $recordsDocument['relations'] = $filterEntries($recordsDocument['relations'], $definition->handle);
            $recordsDocument['actions'] = $filterEntries($recordsDocument['actions'], $definition->handle);
            $recordsDocument['archives'] = $filterEntries($recordsDocument['archives'], $definition->handle);
            $recordsDocument['expected'] = [
                'record_count' => count($recordsDocument['records']),
                'relation_count' => count($recordsDocument['relations']),
                'action_count' => count($recordsDocument['actions']),
                'archive_count' => count($recordsDocument['archives']),
            ];
            $profileDocument['expected'] = ['definition_count' => 1] + $recordsDocument['expected'];
            $package = [
                sprintf('business/%s/profile.json', $profile) => $profileDocument,
                sprintf('business/%s/%s', $profile, $declared['file']) =>
                    $documents['definitions'][$declared['file']],
                sprintf('business/%s/records.json', $profile) => $recordsDocument,
            ];
            $writer->writePackage($directory, $profile, $package);
            $catalog = new FilesystemDemoManifestCatalog($directory);
            $verified = $catalog->business($profile);
            self::assertIsArray($verified['manifest']['expected']);
            self::assertSame(
                count($recordsDocument['records']),
                $verified['manifest']['expected']['record_count'],
            );
            self::assertGreaterThanOrEqual(1, $verified['manifest']['expected']['record_count']);
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
