<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Content\Application\ContentModelService;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\SurfaceArea;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardComposer;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardPreferenceService;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardWidget;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the access-aware administrator dashboard through the shared KIS composition engine.
 *
 * Navigation contributions are the canonical workflow-widget catalog: the renderer first applies actor
 * capability and live extension-trust filtering, then `DashboardComposer` removes the dashboard self link
 * and resolves group and personal KIS preferences against that catalog. Content is merely one optional
 * group of widgets. An actor without `content.read` therefore receives useful permitted business or system
 * workflows without a denied content-service call or an empty content-centric landing page.
 *
 * @since  2.0.0
 */
final readonly class AdministratorDashboardHandler implements RequestHandlerInterface
{
    /**
     * Bind content summaries and the administrator contribution projection to one dashboard composer.
     *
     * @param  ContentService             $content      Supplies only policy-authorized content records.
     * @param  ContentModelService        $models       Supplies only policy-authorized content-type definitions.
     * @param  AdministratorRenderer      $renderer     Renders the shell and projects its visible navigation.
     * @param  DashboardComposer          $dashboard    Resolves groups, user choices, and live widgets.
     * @param  DashboardPreferenceService $preferences Builds authorized personal and access-group controls.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentService $content,
        private ContentModelService $models,
        private AdministratorRenderer $renderer,
        private DashboardComposer $dashboard,
        private DashboardPreferenceService $preferences,
    ) {
    }

    /**
     * Compose and render the dashboard for the authenticated administrator.
     *
     * The content projection remains deliberately bounded to the latest 500 readable records; its wording
     * explicitly describes that bounded basis instead of presenting the number as an installation-wide
     * count. Every workflow card and shortcut is derived from the same filtered rows as the sidebar and
     * command palette. Preferences carry only those stable semantic identifiers, never hrefs or markup.
     *
     * @param   ServerRequestInterface  $request  Authenticated and route-authorized administrator request.
     *
     * @return  ResponseInterface  No-store HTML because the page carries a CSRF token and actor preferences.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        $context = AdministratorRequest::context($request);
        $capabilities = AdministratorRequest::capabilityMap($request);
        $navigation = $this->renderer->visibleNavigation($capabilities);
        $surface = SurfaceId::fromString('core.administrator.dashboard');
        $workflowIds = DashboardComposer::workflowIdentifiers(
            SurfaceArea::Administrator,
            $surface,
            $navigation,
        );
        $coreWidgets = [$this->accessContextWidget($context, count($workflowIds))];
        $defaultWidgets = ['core.dashboard.administrator-context'];

        if (isset($capabilities['content.read'])) {
            $records = $this->content->list($context, 500, true);
            $types = $this->models->contentTypes($context);
            $coreWidgets = [
                ...$this->contentWidgets($records, count($types)),
                ...$coreWidgets,
            ];
            $defaultWidgets = [
                'core.dashboard.content-summary',
                'core.dashboard.recent-content',
                ...$defaultWidgets,
            ];
        }

        $visibleIds = array_fill_keys($workflowIds, true);
        foreach (
            [
                'core.business-records',
                'core.business-reports',
                'core.automation',
                'core.content',
                'core.create-content',
                'core.access',
                'core.settings',
            ] as $identifier
        ) {
            if (isset($visibleIds[$identifier])) {
                $defaultWidgets[] = $identifier;
            }
        }
        $defaultWidgets = array_slice($defaultWidgets, 0, 8);
        foreach (array_keys($visibleIds) as $identifier) {
            if (
                $identifier !== 'core.dashboard'
                && !in_array($identifier, $defaultWidgets, true)
                && count($defaultWidgets) < 8
            ) {
                $defaultWidgets[] = $identifier;
            }
        }
        $defaultShortcuts = array_slice(array_values(array_filter(
            $defaultWidgets,
            static fn (string $identifier): bool => isset($visibleIds[$identifier]),
        )), 0, 6);
        if ($defaultShortcuts === []) {
            $defaultShortcuts = array_slice(array_keys($visibleIds), 0, 6);
        }

        $dashboard = $this->dashboard->compose(
            SurfaceArea::Administrator,
            $surface,
            ContributionOwner::core(),
            $context,
            $navigation,
            $coreWidgets,
            $defaultWidgets,
            $defaultShortcuts,
        );
        $view = $dashboard->toArray();
        $view['preference_forms'] = $this->preferences->formModels(
            $context,
            SurfaceArea::Administrator,
            $surface,
            ContributionOwner::core(),
            isset($capabilities['users.manage']),
            $dashboard->selectedWidgetIds,
            $dashboard->selectedShortcutIds,
        );
        $view['preference_action'] = '/administrator/dashboard/preferences';
        $this->applyPreferenceNotice($view, $request->getQueryParams());

        return new HtmlResponse($this->renderer->render('dashboard', [
            'csrf' => $session->csrfToken,
            'capabilities' => $capabilities,
            'dashboard' => $view,
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Describe the server-resolved administrator access context without reading an unrelated subsystem.
     *
     * @param   ExecutionContext  $context        Current trusted context.
     * @param   int               $workflowCount  Bounded live workflows selectable on this dashboard.
     *
     * @return  DashboardWidget  Always-available semantic context card.
     *
     * @since   2.0.0
     */
    private function accessContextWidget(
        ExecutionContext $context,
        int $workflowCount,
    ): DashboardWidget {
        return new DashboardWidget(
            'core.dashboard.administrator-context',
            DashboardWidget::KIND_CONTEXT,
            'core.administrator.dashboard.access_context.title',
            'core.administrator.dashboard.access_context.description',
            'shield',
            size: DashboardWidget::SIZE_LARGE,
            data: ['items' => [
                [
                    'label' => 'core.administrator.dashboard.access_context.site_label',
                    'value' => $context->site()->identifier(),
                ],
                [
                    'label' => 'core.administrator.dashboard.access_context.workspace_label',
                    'value' => $context->workspace()?->identifier() ?? '—',
                ],
                [
                    'label' => 'core.administrator.dashboard.access_context.workflows_label',
                    'value' => $workflowCount,
                ],
            ]],
        );
    }

    /**
     * Build bounded content-owned summary and activity widgets.
     *
     * Recent rows are informational. Their collection-level capability map cannot preserve grant reach,
     * so only the authorized collection action is exposed and no per-record editor href is inferred here.
     *
     * @param   list<ContentRecord>  $records    Latest readable content slice, newest first.
     * @param   int                  $typeCount  Number of readable current content types.
     *
     * @return  list<DashboardWidget>  Markup-free core models for the shared KIS renderer.
     *
     * @since   2.0.0
     */
    private function contentWidgets(array $records, int $typeCount): array
    {
        $counts = ['total' => 0, 'published' => 0, 'review' => 0, 'trashed' => 0];
        foreach ($records as $record) {
            $counts['total']++;
            if ($record->deletedAt !== null) {
                $counts['trashed']++;
                continue;
            }
            $status = $record->entry->statusKey();
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $active = max(1, $counts['total'] - $counts['trashed']);
        $publishedPercent = min(100, (int) round(($counts['published'] / $active) * 100));

        return [
            new DashboardWidget(
                'core.dashboard.content-summary',
                DashboardWidget::KIND_SUMMARY,
                'core.administrator.dashboard.content_summary.title',
                'core.administrator.dashboard.content_summary.description',
                'content',
                size: DashboardWidget::SIZE_LARGE,
                data: [
                    'search' => [
                        'action' => '/administrator/content',
                        'label' => 'core.administrator.dashboard.content_summary.search_label',
                        'placeholder' => 'core.administrator.dashboard.content_summary.search_placeholder',
                        'button' => 'core.administrator.dashboard.content_summary.search_button',
                    ],
                    'metrics' => [
                        [
                            'label' => 'core.administrator.dashboard.content_summary.total_metric',
                            'value' => $counts['total'],
                        ],
                        [
                            'label' => 'core.administrator.dashboard.content_summary.published_metric',
                            'value' => $counts['published'],
                            'tone' => 'success',
                        ],
                        [
                            'label' => 'core.administrator.dashboard.content_summary.review_metric',
                            'value' => $counts['review'],
                            'tone' => $counts['review'] > 0 ? 'warning' : 'neutral',
                        ],
                        ['label' => 'core.administrator.dashboard.content_summary.types_metric', 'value' => $typeCount],
                    ],
                    'progress' => [
                        'value' => $publishedPercent,
                        'label' => 'core.administrator.dashboard.content_summary.publication_progress',
                        'parameters' => ['percent' => $publishedPercent],
                        'help' => 'core.administrator.dashboard.content_summary.publication_progress_help',
                        'help_parameters' => ['count' => $counts['total']],
                    ],
                ],
            ),
            new DashboardWidget(
                'core.dashboard.recent-content',
                DashboardWidget::KIND_ACTIVITY,
                'core.administrator.dashboard.recent_content.title',
                'core.administrator.dashboard.recent_content.description',
                'content',
                size: DashboardWidget::SIZE_LARGE,
                data: [
                    'items' => array_map(
                        static fn (ContentRecord $record): array => [
                            'title' => $record->entry->title(),
                            'detail' => $record->updatedAt->format('Y-m-d H:i'),
                            'status' => $record->deletedAt !== null
                                ? 'trashed'
                                : $record->entry->statusKey(),
                            'status_tone' => $record->deletedAt !== null
                                ? 'danger'
                                : match ($record->entry->statusKey()) {
                                    'published' => 'success',
                                    'review' => 'warning',
                                    default => 'neutral',
                                },
                        ],
                        array_slice($records, 0, 6),
                    ),
                    'empty_title' => 'core.administrator.dashboard.recent_content.empty_title',
                    'empty_message' => 'core.administrator.dashboard.recent_content.empty_message',
                    'action' => [
                        'href' => '/administrator/content',
                        'label' => 'core.administrator.dashboard.recent_content.view_all',
                    ],
                ],
            ),
        ];
    }

    /**
     * Add only closed, server-selected preference result notices to the dashboard contract.
     *
     * @param   array<string, mixed>    $view   Dashboard view updated in place.
     * @param   array<array-key, mixed> $query  Untrusted query values inspected only as exact flags.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function applyPreferenceNotice(array &$view, array $query): void
    {
        $view['preference_saved'] = ($query['dashboard-saved'] ?? null) === '1';
        $error = $query['dashboard-error'] ?? null;
        $view['preference_error'] = match ($error) {
            'conflict' => 'core.interface_standard.dashboard.conflict_notice',
            'invalid' => 'core.interface_standard.dashboard.invalid_notice',
            default => '',
        };
        $view['preference_open'] = $view['preference_saved'] || $view['preference_error'] !== '';
    }
}
