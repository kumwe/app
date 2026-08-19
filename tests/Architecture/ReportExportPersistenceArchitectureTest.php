<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ReportExportPersistenceArchitectureTest extends TestCase
{
    public function testProductionBindsDatabaseMetadataAndPrivateFilesystemBytes(): void
    {
        $root = dirname(__DIR__, 2);
        $container = file_get_contents($root . '/src/Kernel/ContainerFactory.php');
        $migration = file_get_contents(
            $root . '/src/Infrastructure/Persistence/Migration/BusinessIntegrationSdkMigration.php',
        );

        self::assertIsString($container);
        self::assertIsString($migration);
        self::assertStringContainsString('new DoctrineExportArtifactRepository(', $container);
        self::assertStringContainsString('self::service($container, Connection::class)', $container);
        self::assertStringContainsString('self::service($container, TableNames::class)', $container);
        self::assertStringContainsString('self::service($container, TransactionManager::class)', $container);
        self::assertStringNotContainsString('new FilesystemExportArtifactRepository(', $container);
        self::assertStringContainsString('new FilesystemExportArtifactStorage(', $container);
        self::assertStringContainsString("raw('business_report_export_artifacts')", $migration);
    }
}
