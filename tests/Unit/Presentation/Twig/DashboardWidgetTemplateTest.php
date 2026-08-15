<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Twig;

use Kumwe\CMS\Tests\Support\InterfaceTranslation;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Verifies the strict translated dashboard widget contract in the protected component environment.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class DashboardWidgetTemplateTest extends TestCase
{
    /**
     * Render semantic timestamps, translated states, and an unknown icon without a shell sprite.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testActivityWidgetFormatsDynamicStateThroughProtectedContracts(): void
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 4) . '/templates/interface-standard', 'kis');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(InterfaceTranslation::twigExtension());
        $html = $twig->render('@kis/dashboard-widget.twig', [
            'widget_index' => 1,
            'widget' => [
                'id' => 'core.dashboard.recent-content',
                'kind' => 'activity',
                'title' => 'core.administrator.dashboard.recent_content.title',
                'description' => 'core.administrator.dashboard.recent_content.description',
                'icon' => 'vendor-inspections',
                'group' => '',
                'size' => 'large',
                'href' => null,
                'message_ids' => true,
                'data' => [
                    'items' => [[
                        'title' => 'Quarterly report',
                        'detail' => '2026-08-12T00:00:00+00:00',
                        'detail_label' => 'core.administrator.dashboard.recent_content.updated_at',
                        'detail_parameters' => ['at' => 1_786_492_800],
                        'status' => 'review',
                        'status_label' => 'core.administrator.dashboard.recent_content.status_review',
                        'status_parameters' => [],
                        'status_tone' => 'warning',
                    ]],
                    'empty_title' => 'core.administrator.dashboard.recent_content.empty_title',
                    'empty_message' => 'core.administrator.dashboard.recent_content.empty_message',
                    'action' => [
                        'href' => '/administrator/content',
                        'label' => 'core.administrator.dashboard.recent_content.view_all',
                    ],
                ],
            ],
        ]);

        self::assertStringContainsString('data-kis-dashboard-icon="dashboard"', $html);
        self::assertStringContainsString('data-kis-dashboard-icon-fallback="true"', $html);
        self::assertStringContainsString('datetime="2026-08-12T00:00:00+00:00"', $html);
        self::assertStringContainsString('Updated 12 Aug 2026 at 00:00', $html);
        self::assertMatchesRegularExpression('/>\s*In review\s*<\/span>/', $html);
    }
}
