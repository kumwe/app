<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversNothing]
final class BusinessRuntimeBoundaryTest extends TestCase
{
    public function testBusinessRuntimeIsSeparateFromCmsContentAndHasNoUniversalRecordStore(): void
    {
        $root = dirname(__DIR__, 2);
        $runtime = $this->source('src/BusinessSchema') . $this->source('src/BusinessRecord');
        $migration = $this->contents(
            'src/Infrastructure/Persistence/Migration/BusinessTransactionalRuntimeMigration.php',
        );

        self::assertStringNotContainsString('Kumwe\\App\\Content\\', $runtime);
        self::assertStringNotContainsString('Kumwe\\App\\BusinessRecord\\Query\\', $runtime);
        self::assertStringNotContainsString(
            'Kumwe\\App\\BusinessRecord\\Application\\Query\\BusinessRecordQueryPurpose',
            $runtime,
        );
        self::assertStringNotContainsString('Kumwe\\App\\BusinessRecord\\Domain\\ZonedDateTimeValue', $runtime);
        self::assertDirectoryDoesNotExist($root . '/src/BusinessRecord/Query');
        self::assertFileDoesNotExist(
            $root . '/src/BusinessRecord/Application/Query/BusinessRecordQueryPurpose.php',
        );
        self::assertFileDoesNotExist($root . '/src/BusinessRecord/Domain/ZonedDateTimeValue.php');
        self::assertDoesNotMatchRegularExpression(
            '/(?:raw|quoted)\([\'\"]business_records[\'\"]\)|new\s+Table\([^\n]*business_records/i',
            $runtime . $migration,
            'Business records must use generated typed tables, never a universal JSON/EAV table.',
        );
    }

    public function testInfrastructureConnectionsNeverCrossIntoBusinessDeliveryCode(): void
    {
        $outsideInfrastructure = $this->source('src/BusinessSchema/Application')
            . $this->source('src/BusinessSchema/Domain')
            . $this->source('src/BusinessSchema/Delivery')
            . $this->source('src/BusinessRecord/Application')
            . $this->source('src/BusinessRecord/Domain')
            . $this->source('src/BusinessSurface/Application')
            . $this->source('src/BusinessSurface/Delivery')
            . $this->source('src/BusinessSurface/Presentation');

        self::assertStringNotContainsString('Doctrine\\DBAL\\Connection', $outsideInfrastructure);
        self::assertStringNotContainsString('Doctrine\\ORM\\EntityManager', $outsideInfrastructure);
        self::assertStringNotContainsString(
            'Kumwe\\App\\BusinessRecord\\Infrastructure',
            $this->source('src/BusinessSurface/Delivery'),
            'Generated delivery adapters must use application contracts instead of record infrastructure.',
        );
    }

    public function testPublicRuntimeContractsCannotAcceptSqlOrPhysicalIdentifiers(): void
    {
        $recordService = $this->contents('src/BusinessRecord/Application/BusinessRecordService.php');
        $recordApplicationContracts = $this->source('src/BusinessRecord/Application/Command')
            . $this->source('src/BusinessRecord/Application/Query');
        $schemaDelivery = $this->source('src/BusinessSchema/Delivery');

        self::assertDoesNotMatchRegularExpression(
            '/function\s+\w+\s*\([^)]*\$(?:sql|table|column|orderBy|expression)\b/i',
            $recordService . $recordApplicationContracts . $schemaDelivery,
            'Public business runtime boundaries must accept typed handles and specifications, never SQL tokens.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/<(?:input|select|textarea)[^>]+name=["\'](?:sql|plan|query|expression)["\']/i',
            $this->contents('templates/administrator/business-schema-plans.twig'),
            'The schema administrator must not expose raw SQL or plan authoring.',
        );
    }

    public function testSchemaAdministratorUsesIndependentCapabilityAndHighImpactGates(): void
    {
        $container = $this->contents('src/Kernel/ContainerFactory.php');
        $approval = $this->contents(
            'src/BusinessSchema/Delivery/Administrator/ApproveBusinessSchemaPlanHandler.php',
        );
        $purge = $this->contents(
            'src/BusinessSchema/Delivery/Administrator/CreateBusinessSchemaPurgePlanHandler.php',
        );
        $evidence = $this->contents(
            'src/BusinessSchema/Delivery/Administrator/RecordBusinessSchemaRecoveryEvidenceHandler.php',
        );

        foreach (
            [
                'administrator.business-schema-plans' => 'business.schema.read',
                'administrator.business-schema-plans.plan' => 'business.schema.plan',
                'administrator.business-schema-plans.approve' => 'business.schema.approve',
                'administrator.business-schema-plans.execute' => 'business.schema.execute',
                'administrator.business-schema-plans.recovery-evidence' => 'business.schema.recover',
                'administrator.business-schema-plans.recover' => 'business.schema.recover',
                'administrator.business-schema-plans.purge' => 'business.schema.destructive',
            ] as $route => $capability
        ) {
            $offset = strpos($container, "'" . $route . "'");
            self::assertNotFalse($offset, sprintf('Schema route %s is missing.', $route));
            $snippet = substr($container, max(0, $offset - 450), 700);
            if ($capability !== 'business.schema.read') {
                self::assertStringContainsString('AdministratorCsrfMiddleware::class', $snippet);
            }
            self::assertStringContainsString("'" . $capability . "'", $snippet);
        }

        self::assertStringContainsString('HighImpactCredentialGuard', $approval);
        self::assertStringContainsString('hash_equals($plan->checksum(), $confirmation)', $approval);
        self::assertStringContainsString('HighImpactCredentialGuard', $purge);
        self::assertStringContainsString("'business.schema.purge-plan'", $purge);
        self::assertStringContainsString('BusinessSchemaEnvironment', $evidence);
        self::assertStringContainsString('HighImpactCredentialGuard', $evidence);
        self::assertStringContainsString("'business.schema.recovery-evidence'", $evidence);
        self::assertStringNotContainsString("'database_driver'", $evidence);
        self::assertDoesNotMatchRegularExpression(
            '/name=["\'](?:database_driver|database_server_version|application_release|source_schema_checksum)["\']/',
            $this->contents('templates/administrator/business-schema-plans.twig'),
            'Recovery environment identity must come from trusted runtime configuration.',
        );
    }

    private function source(string $path): string
    {
        $root = dirname(__DIR__, 2) . '/' . $path;
        if (!is_dir($root)) {
            return '';
        }
        $source = '';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $contents = file_get_contents($file->getPathname());
                self::assertIsString($contents);
                $source .= $contents;
            }
        }

        return $source;
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents, sprintf('Could not read %s.', $path));

        return $contents;
    }
}
