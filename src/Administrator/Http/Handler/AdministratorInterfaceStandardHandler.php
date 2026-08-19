<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Presentation\Application\Dashboard\DashboardWidget;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Renders the production component gallery that proves the current KIS contract.
 *
 * The gallery uses the same server-rendered Twig components and compiled assets as product screens, so
 * component browser tests exercise production behavior instead of a disconnected design mock. It is
 * read-only and sits behind the ordinary administrator boundary; no interface policy is authored here.
 *
 * @since  2.0.0
 */
final readonly class AdministratorInterfaceStandardHandler implements RequestHandlerInterface
{
    /**
     * Stable gallery concerns rendered as URL-addressable tabs.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const TABS = [
        'overview' => 'Overview',
        'collections' => 'Collections',
        'forms' => 'Forms and drawers',
        'safety' => 'Safety and states',
    ];

    /**
     * Bind the gallery to the ordinary administrator renderer and its recovery behavior.
     *
     * @param  AdministratorRenderer  $renderer  Renders the production KIS gallery template.
     *
     * @since  2.0.0
     */
    public function __construct(private AdministratorRenderer $renderer)
    {
    }

    /**
     * Render the selected gallery concern without accepting arbitrary template or component names.
     *
     * @param   ServerRequestInterface  $request  Authorized administrator request carrying an optional
     *          bounded `tab` query value.
     *
     * @return  ResponseInterface  No-store HTML containing representative KIS components and states.
     *
     * @throws  \InvalidArgumentException  When the administrator middleware did not attach a session.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        $query = $request->getQueryParams();
        $requested = is_string($query['tab'] ?? null) ? trim($query['tab']) : '';
        $active = array_key_exists($requested, self::TABS) ? $requested : 'overview';
        $tabs = [];
        foreach (self::TABS as $identifier => $label) {
            $tabs[] = [
                'id' => $identifier,
                'label' => $label,
                'href' => '/administrator/interface-standard?tab=' . rawurlencode($identifier),
            ];
        }

        return new HtmlResponse($this->renderer->render('interface-standard', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'active_tab' => $active,
            'gallery_tabs' => $tabs,
            'dashboard_gallery' => $this->dashboardGallery($session->principal->subject()),
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Build one read-only representative projection for the protected dashboard components.
     *
     * The fixture uses the production typed widget model and the same preference view contract as the
     * administrator and portal dashboards. The gallery template disables every descendant form control,
     * so these representative versions and selections cannot reach a preference mutation handler.
     *
     * @param   string  $actorId  Canonical UUID of the signed-in actor represented by the personal form.
     *
     * @return  array{
     *              widgets: list<array<string, mixed>>,
     *              preference_forms: list<array<string, mixed>>,
     *              preference_action: string,
     *              preference_open: bool,
     *              preference_saved: bool,
     *              preference_error: string,
     *              access_group_browser: array<string, bool|int|string>,
     *              workflow_browser: array<string, bool|int|string>
     *          }  Sanitized dashboard state containing no live repository or mutation dependency.
     *
     * @since   2.0.0
     */
    private function dashboardGallery(string $actorId): array
    {
        $workflow = DashboardWidget::fromNavigation([
            'id' => 'core.interface-standard.gallery-workflow',
            'label' => 'Partner inspection workflow',
            'description' => 'Unknown contributed icon names use the protected dashboard fallback.',
            'href' => '/administrator/interface-standard?tab=overview#dashboard-gallery-preferences',
            'icon' => 'partner-console',
            'group' => 'Partner operations',
            'order' => 40,
        ])->toArray();
        $widgets = [
            (new DashboardWidget(
                'core.interface-standard.gallery-summary',
                DashboardWidget::KIND_SUMMARY,
                'Operational summary',
                'Bounded metrics make the next review decision visible.',
                'dashboard',
                'Reference',
                DashboardWidget::SIZE_MEDIUM,
                [
                    'metrics' => [
                        ['label' => 'Open work', 'value' => 18, 'tone' => 'neutral'],
                        ['label' => 'Needs review', 'value' => 4, 'tone' => 'warning'],
                    ],
                ],
                false,
            ))->toArray(),
            (new DashboardWidget(
                'core.interface-standard.gallery-activity',
                DashboardWidget::KIND_ACTIVITY,
                'core.administrator.dashboard.recent_content.title',
                'core.administrator.dashboard.recent_content.description',
                'status',
                'Reference',
                DashboardWidget::SIZE_LARGE,
                [
                    'items' => [[
                        'title' => 'Quarterly inspection review',
                        'detail' => '2026-08-15T09:30:00+02:00',
                        'detail_label' => 'core.administrator.dashboard.recent_content.updated_at',
                        'detail_parameters' => ['at' => 1_786_779_000],
                        'status' => 'review',
                        'status_label' => 'core.administrator.dashboard.recent_content.status_review',
                        'status_parameters' => [],
                        'status_tone' => 'warning',
                    ]],
                    'empty_title' => 'core.administrator.dashboard.recent_content.empty_title',
                    'empty_message' => 'core.administrator.dashboard.recent_content.empty_message',
                ],
            ))->toArray(),
            (new DashboardWidget(
                'core.interface-standard.gallery-context',
                DashboardWidget::KIND_CONTEXT,
                'core.administrator.dashboard.access_context.title',
                'core.administrator.dashboard.access_context.description',
                'home',
                'Reference',
                DashboardWidget::SIZE_MEDIUM,
                [
                    'items' => [
                        [
                            'label' => 'core.administrator.dashboard.access_context.site_label',
                            'value' => 'default',
                        ],
                        [
                            'label' => 'core.administrator.dashboard.access_context.workspace_label',
                            'value' => 'administrator',
                        ],
                        [
                            'label' => 'core.administrator.dashboard.access_context.workflows_label',
                            'value' => 6,
                        ],
                    ],
                ],
            ))->toArray(),
            $workflow,
        ];
        $availableShortcuts = [$workflow];
        $availableWidgets = $widgets;

        return [
            'widgets' => $widgets,
            'preference_forms' => [
                [
                    'scope' => 'user',
                    'scope_id' => $actorId,
                    'scope_label' => 'core.interface_standard.dashboard.personal_eyebrow',
                    'label' => 'Reference operator',
                    'message_ids' => false,
                    'help' => 'core.interface_standard.dashboard.personal_help',
                    'group_code' => null,
                    'available_widgets' => $availableWidgets,
                    'selected_widget_ids' => [
                        'core.interface-standard.gallery-summary',
                        'core.interface-standard.gallery-workflow',
                    ],
                    'widget_order' => [
                        'core.interface-standard.gallery-summary' => 1,
                        'core.interface-standard.gallery-workflow' => 2,
                    ],
                    'widget_version' => 3,
                    'available_shortcuts' => $availableShortcuts,
                    'selected_shortcut_ids' => ['core.interface-standard.gallery-workflow'],
                    'shortcut_order' => ['core.interface-standard.gallery-workflow' => 1],
                    'shortcut_version' => 2,
                ],
                [
                    'scope' => 'role-workspace',
                    'scope_id' => 'role:00000000-0000-7000-8000-000000000702',
                    'scope_label' => 'core.interface_standard.dashboard.access_group_eyebrow',
                    'label' => 'Operations reviewers',
                    'message_ids' => false,
                    'help' => 'core.interface_standard.dashboard.access_group_help',
                    'group_code' => 'operations-reviewers',
                    'available_widgets' => $availableWidgets,
                    'selected_widget_ids' => ['core.interface-standard.gallery-activity'],
                    'widget_order' => ['core.interface-standard.gallery-activity' => 1],
                    'widget_version' => 7,
                    'available_shortcuts' => $availableShortcuts,
                    'selected_shortcut_ids' => [],
                    'shortcut_order' => [],
                    'shortcut_version' => 4,
                ],
            ],
            'preference_action' => '/administrator/interface-standard?tab=overview',
            'preference_open' => true,
            'preference_saved' => false,
            'preference_error' => '',
            'access_group_browser' => [
                'available' => true,
                'active' => true,
                'search' => '',
                'page' => 2,
                'result_count' => 1,
                'has_previous' => true,
                'has_next' => true,
                'browse_limit' => false,
                'action' => '/administrator/interface-standard',
                'clear_href' => '/administrator/interface-standard?tab=overview#dashboard-gallery-preferences',
                'previous_href' => '/administrator/interface-standard?tab=overview#dashboard-gallery-preferences',
                'next_href' => '/administrator/interface-standard?tab=overview#dashboard-gallery-preferences',
            ],
            'workflow_browser' => [
                'available' => true,
                'active' => true,
                'search' => 'inspection',
                'page' => 1,
                'result_count' => 1,
                'has_previous' => false,
                'has_next' => false,
                'browse_limit' => false,
                'action' => '/administrator/interface-standard',
                'clear_href' => '/administrator/interface-standard?tab=overview#dashboard-gallery-preferences',
                'previous_href' => '/administrator/interface-standard?tab=overview#dashboard-gallery-preferences',
                'next_href' => '/administrator/interface-standard?tab=overview#dashboard-gallery-preferences',
            ],
        ];
    }
}
