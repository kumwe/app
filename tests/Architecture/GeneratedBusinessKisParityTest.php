<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Locks administrator and portal generated-business delivery to the same KIS remediation contract.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class GeneratedBusinessKisParityTest extends TestCase
{
    /**
     * Proves every Phase 4 surface opts into the shared component and stylesheet vocabulary.
     *
     * @return  void
     *
     * @throws  JsonException  When the committed parity artifact is malformed.
     *
     * @since   2.0.0
     */
    public function testGeneratedAdministratorAndPortalSurfacesShareKisMarkers(): void
    {
        $root = dirname(__DIR__, 2);
        $artifact = json_decode(
            (string) file_get_contents(
                $root . '/tests/Fixtures/InterfaceStandard/phase-4-generated-surface-parity.json',
            ),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(4, $artifact['phase']);
        foreach ($artifact['surfaces'] as $surface) {
            foreach (['administrator', 'portal'] as $adapter) {
                $template = (string) file_get_contents(sprintf(
                    '%s/templates/%s/%s',
                    $root,
                    $adapter,
                    $surface['template'],
                ));
                self::assertStringContainsString('data-kis-business', $template, $surface['id']);
                self::assertStringContainsString($surface['marker'], $template, $surface['id']);
            }
        }

        $stylesheet = (string) file_get_contents($root . '/' . $artifact['shared_stylesheet']);
        self::assertStringContainsString('[data-kis-business]', $stylesheet);
        self::assertStringContainsString('.kis-business-collection', $stylesheet);
        self::assertStringContainsString('.kis-business-report-results', $stylesheet);
        self::assertDoesNotMatchRegularExpression('/(?:extension-owner|data-extension|owner-)/i', $stylesheet);
    }

    /**
     * Proves both report adapters use one strict browser mapper and one shared parameter component.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReportAndCollectionDeliveryRetainSharedPolicySeams(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['Administrator/AdministratorReportHandler.php', 'Portal/PortalReportHandler.php'] as $handler) {
            $source = (string) file_get_contents($root . '/src/BusinessReporting/Delivery/' . $handler);
            self::assertStringContainsString('ReportParameterInput::map(', $source);
            self::assertStringContainsString('BusinessRecordQueryPurpose::Export', $source);
            self::assertStringContainsString('ReportUnavailable', $source);
        }
        $controller = (string) file_get_contents(
            $root . '/src/BusinessSurface/Delivery/Browser/GeneratedBusinessBrowserController.php',
        );
        self::assertStringContainsString('BusinessCollectionPresentation::fromQuery(', $controller);
        self::assertStringContainsString("'record_task'", $controller);
        self::assertFileExists($root . '/templates/interface-standard/report-parameter-fields.twig');
        self::assertFileExists($root . '/templates/interface-standard/task-navigation.twig');
    }
}
