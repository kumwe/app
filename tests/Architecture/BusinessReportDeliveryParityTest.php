<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class BusinessReportDeliveryParityTest extends TestCase
{
    public function testBrowserAdaptersExposeCsrfProtectedExecutionAndVerifiedDownload(): void
    {
        $root = dirname(__DIR__, 2);
        $administrator = file_get_contents($root . '/templates/administrator/business-report.twig');
        $portal = file_get_contents($root . '/templates/portal/business-report.twig');
        $administratorHandler = file_get_contents(
            $root . '/src/BusinessReporting/Delivery/Administrator/AdministratorReportHandler.php',
        );
        $portalHandler = file_get_contents(
            $root . '/src/BusinessReporting/Delivery/Portal/PortalReportHandler.php',
        );

        self::assertIsString($administrator);
        self::assertIsString($portal);
        self::assertStringContainsString('name="_csrf"', $administrator);
        self::assertStringContainsString('value="{{ csrf }}"', $administrator);
        self::assertStringContainsString("\$data['csrf'] = \$session->csrfToken", $administratorHandler);
        self::assertStringContainsString('name="_csrf"', $portal);
        self::assertStringContainsString('portal_session.csrfToken', $portal);
        self::assertStringContainsString("\$operation === 'export_download'", $administratorHandler);
        self::assertStringContainsString("\$operation === 'export_download'", $portalHandler);
        self::assertStringContainsString("'X-Content-Type-Options' => 'nosniff'", $administratorHandler);
        self::assertStringContainsString("'X-Content-Type-Options' => 'nosniff'", $portalHandler);
        self::assertStringContainsString('role="alert"', $administrator);
        self::assertStringContainsString('role="alert"', $portal);
        self::assertStringContainsString('$status = 422;', $administratorHandler);
        self::assertStringContainsString('$status = 422;', $portalHandler);
        self::assertStringNotContainsString('$exception->getMessage()', $administratorHandler);
        self::assertStringNotContainsString('$exception->getMessage()', $portalHandler);
    }

    public function testEveryMachineAdapterUsesTheSharedReportAndExportServices(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (
            [
            '/src/BusinessReporting/Delivery/Api/ReportApiHandler.php',
            '/src/BusinessReporting/Delivery/Console/ReportCommand.php',
            '/src/Infrastructure/Mcp/ReportMcpHandlers.php',
            ] as $path
        ) {
            $contents = file_get_contents($root . $path);
            self::assertIsString($contents);
            self::assertStringContainsString('ReportService', $contents);
            self::assertStringContainsString('ExportService', $contents);
        }
    }

    public function testReportNavigationTracksTheActivePortalReportRegistry(): void
    {
        $root = dirname(__DIR__, 2);
        $core = file_get_contents($root . '/src/Extension/Contribution/CoreExtensionContributions.php');
        $visibility = file_get_contents(
            $root . '/src/BusinessSurface/Delivery/Portal/GeneratedBusinessPortalNavigationVisibility.php',
        );
        $reports = file_get_contents($root . '/src/BusinessReporting/Application/ReportService.php');

        self::assertIsString($core);
        self::assertIsString($visibility);
        self::assertIsString($reports);
        self::assertStringContainsString("'core.business-reports'", $core);
        self::assertStringContainsString("'core.portal-business-reports'", $core);
        self::assertStringContainsString('REPORT_NAVIGATION_ID', $visibility);
        self::assertStringContainsString('$this->reports->available(', $visibility);
        self::assertStringContainsString('$this->reports->all()', $reports);
        self::assertStringContainsString('$report->portalVisible', $reports);
        self::assertStringContainsString('$report->requiredCapability', $reports);
    }
}
