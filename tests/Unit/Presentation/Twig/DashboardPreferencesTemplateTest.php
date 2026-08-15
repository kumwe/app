<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Twig;

use Kumwe\CMS\Tests\Support\InterfaceTranslation;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Verifies bounded dashboard-preference paging remains visible in the protected no-JavaScript form.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class DashboardPreferencesTemplateTest extends TestCase
{
    /**
     * Show search, page state, and fixed previous/next links without JavaScript.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAccessGroupBrowserIsExplicitAndUsableWithoutJavaScript(): void
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 4) . '/templates/interface-standard', 'kis');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(InterfaceTranslation::twigExtension());
        $html = $twig->render('@kis/dashboard-preferences.twig', [
            'csrf' => 'csrf-token',
            'dashboard' => [
                'preference_forms' => [[
                    'scope' => 'user',
                    'scope_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
                    'scope_label' => 'core.interface_standard.dashboard.personal_eyebrow',
                    'label' => 'core.interface_standard.dashboard.personal_label',
                    'message_ids' => true,
                    'help' => 'core.interface_standard.dashboard.personal_help',
                    'available_widgets' => [],
                    'available_shortcuts' => [],
                    'selected_widget_ids' => [],
                    'widget_order' => [],
                    'widget_version' => 0,
                    'selected_shortcut_ids' => [],
                    'shortcut_order' => [],
                    'shortcut_version' => 0,
                ]],
                'available_widgets' => [],
                'available_shortcuts' => [],
                'preference_action' => '/administrator/dashboard/preferences?dashboard_group_page=65',
                'preference_open' => false,
                'preference_saved' => false,
                'preference_error' => '',
                'access_group_browser' => [
                    'available' => true,
                    'active' => true,
                    'search' => 'Finance reviewers',
                    'page' => 65,
                    'result_count' => 1,
                    'has_previous' => true,
                    'has_next' => true,
                    'browse_limit' => false,
                    'action' => '/administrator',
                    'clear_href' => '/administrator#dashboard-customization',
                    'previous_href' => '/administrator?dashboard_group_page=64#dashboard-customization',
                    'next_href' => '/administrator?dashboard_group_page=66#dashboard-customization',
                ],
                'workflow_browser' => [
                    'available' => true,
                    'active' => true,
                    'search' => 'Sales orders',
                    'page' => 16,
                    'result_count' => 32,
                    'has_previous' => true,
                    'has_next' => true,
                    'browse_limit' => false,
                    'action' => '/administrator',
                    'clear_href' => '/administrator?dashboard_group_search=Finance%20reviewers'
                        . '&dashboard_group_page=65#dashboard-customization',
                    'previous_href' => '/administrator?dashboard_group_search=Finance%20reviewers'
                        . '&dashboard_group_page=65&dashboard_workflow_search=Sales%20orders'
                        . '&dashboard_workflow_page=15#dashboard-customization',
                    'next_href' => '/administrator?dashboard_group_search=Finance%20reviewers'
                        . '&dashboard_group_page=65&dashboard_workflow_search=Sales%20orders'
                        . '&dashboard_workflow_page=17#dashboard-customization',
                ],
            ],
        ]);

        self::assertStringContainsString('role="status"', $html);
        self::assertStringContainsString('Access-group defaults', $html);
        self::assertStringContainsString('name="dashboard_group_search"', $html);
        self::assertStringContainsString('name="dashboard_workflow_search"', $html);
        self::assertStringContainsString('name="dashboard_workflow_page" value="16"', $html);
        self::assertStringContainsString('name="dashboard_group_page" value="65"', $html);
        self::assertStringContainsString('Workflow choices', $html);
        self::assertStringContainsString('value="Finance reviewers"', $html);
        self::assertStringContainsString('dashboard_group_page=64#dashboard-customization', $html);
        self::assertStringContainsString('dashboard_group_page=66#dashboard-customization', $html);
        self::assertSame(2, substr_count(
            $html,
            '<form action="/administrator/dashboard/preferences?dashboard_group_page=65"',
        ));
    }
}
