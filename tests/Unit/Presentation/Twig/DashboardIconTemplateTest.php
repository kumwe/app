<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Twig;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Verifies dashboard icons resolve without relying on a theme-owned SVG sprite.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class DashboardIconTemplateTest extends TestCase
{
    /**
     * Render a known extension icon through the protected inline registry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testKnownExtensionIconRendersWithoutShellSprite(): void
    {
        $html = $this->twig()->render('@kis/dashboard-icon.twig', ['icon' => 'extensions']);

        self::assertStringContainsString('data-kis-dashboard-icon="extensions"', $html);
        self::assertStringContainsString('data-kis-dashboard-icon-fallback="false"', $html);
        self::assertStringNotContainsString('<use', $html);
    }

    /**
     * Render an unknown but grammatically valid contribution icon as the guaranteed generic glyph.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnknownExtensionIconUsesGenericFallback(): void
    {
        $html = $this->twig()->render('@kis/dashboard-icon.twig', ['icon' => 'vendor-inspections']);

        self::assertStringContainsString('data-kis-dashboard-icon="dashboard"', $html);
        self::assertStringContainsString('data-kis-dashboard-icon-fallback="true"', $html);
        self::assertStringContainsString('M4 13h6V4H4v9', $html);
        self::assertStringNotContainsString('vendor-inspections', $html);
    }

    /**
     * Build the strict protected-component environment used by either administrator shell.
     *
     * @return  Environment  Strict Twig renderer independent of a theme layout.
     *
     * @since   2.0.0
     */
    private function twig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 4) . '/templates/interface-standard', 'kis');

        return new Environment($loader, ['strict_variables' => true]);
    }
}
