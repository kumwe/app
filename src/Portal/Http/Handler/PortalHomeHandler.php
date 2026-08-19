<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Http\Handler;

use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceService;
use Kumwe\App\Delivery\Http\Dashboard\DashboardPreferenceQueryDecoder;
use Kumwe\App\Extension\Contribution\ContributionOwner;
use Kumwe\App\InterfaceStandard\SurfaceArea;
use Kumwe\App\InterfaceStandard\SurfaceId;
use Kumwe\App\Portal\Http\PortalRequest;
use Kumwe\App\Portal\Presentation\PortalRenderer;
use Kumwe\App\Presentation\Application\Dashboard\DashboardComposer;
use Kumwe\App\Presentation\Application\Dashboard\DashboardPreferenceFormPresenter;
use Kumwe\App\Presentation\Application\Dashboard\DashboardPreferenceFormProjection;
use Kumwe\App\Presentation\Application\Dashboard\DashboardWidget;
use Kumwe\App\Presentation\Application\Dashboard\DashboardWorkflowCatalog;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Renders the portal landing page through the same access-aware KIS dashboard engine as administrator.
 *
 * Portal navigation passes both capability/trust admission and the live portal-session visibility policy
 * before it reaches composition. Generated business destinations therefore become workflow widgets only
 * when the current organization and workspace may discover them, and the overview self link is removed.
 *
 * @since  2.0.0
 */
final readonly class PortalHomeHandler implements RequestHandlerInterface
{
    /**
     * Bind the distinct portal shell to shared dashboard preference composition.
     *
     * @param  PortalRenderer                    $renderer         Portal isolation and rendering boundary.
     * @param  DashboardComposer                 $dashboard        Shared KIS dashboard composer.
     * @param  DashboardPreferenceService        $preferences      Authorized query and mutation use case.
     * @param  DashboardPreferenceFormPresenter  $preferenceForms  Typed-state form mapper.
     * @param  DashboardPreferenceQueryDecoder   $preferenceQuery  Defensive GET and same-area URL codec.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PortalRenderer $renderer,
        private DashboardComposer $dashboard,
        private DashboardPreferenceService $preferences,
        private DashboardPreferenceFormPresenter $preferenceForms,
        private DashboardPreferenceQueryDecoder $preferenceQuery,
    ) {
    }

    /**
     * Compose permitted portal workflows and the current server-resolved access context.
     *
     * @param   ServerRequestInterface  $request  Authenticated, portal-authorized request.
     *
     * @return  ResponseInterface  No-store HTML carrying session context and a CSRF token.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = PortalRequest::session($request);
        $context = PortalRequest::context($request);
        $capabilities = PortalRequest::capabilityMap($request);
        $navigation = $this->renderer->visibleNavigation($session);
        $surface = SurfaceId::fromString('core.portal.home');
        $preferenceQuery = $this->preferenceQuery->decode($request->getQueryParams());
        $workflowCatalog = new DashboardWorkflowCatalog(
            SurfaceArea::Portal,
            $surface,
            $navigation,
        );
        $workflowIds = $workflowCatalog->identifiers();
        $preferred = [];
        foreach (
            [
                'core.portal-business-records',
                'core.portal-business-reports',
                'core.portal-approvals',
                'core.portal-security',
            ] as $identifier
        ) {
            if (in_array($identifier, $workflowIds, true)) {
                $preferred[] = $identifier;
            }
        }
        foreach ($workflowIds as $identifier) {
            if (!in_array($identifier, $preferred, true)) {
                $preferred[] = $identifier;
            }
        }
        $preferred = array_slice($preferred, 0, 63);

        $membership = $session->identity->context->membership;
        $contextWidget = new DashboardWidget(
            'core.dashboard.access-context',
            DashboardWidget::KIND_CONTEXT,
            'core.portal.dashboard.access_context.title',
            'core.portal.dashboard.access_context.description',
            'shield',
            size: DashboardWidget::SIZE_LARGE,
            data: ['items' => [
                [
                    'label' => 'core.portal.dashboard.access_context.site_label',
                    'value' => $session->identity->context->site->identifier(),
                ],
                [
                    'label' => 'core.portal.dashboard.access_context.organization_label',
                    'value' => $membership?->organization()->identifier() ?? '—',
                ],
                [
                    'label' => 'core.portal.dashboard.access_context.workspace_label',
                    'value' => $membership?->workspace()?->identifier() ?? '—',
                ],
                [
                    'label' => 'core.portal.dashboard.access_context.areas_label',
                    'value' => count($workflowIds),
                ],
            ]],
        );
        $dashboard = $this->dashboard->compose(
            SurfaceArea::Portal,
            $surface,
            ContributionOwner::core(),
            $context,
            $navigation,
            [$contextWidget],
            ['core.dashboard.access-context', ...$preferred],
            array_slice($preferred, 0, 6),
            $preferenceQuery,
        );
        $view = $dashboard->toArray();
        $preferenceForms = $this->preferenceForms->present(
            $this->preferences->read(
                $context,
                SurfaceArea::Portal,
                $surface,
                ContributionOwner::core(),
                isset($capabilities['users.manage']),
                $preferenceQuery,
            ),
            $dashboard->selectedWidgetIds,
            $dashboard->selectedShortcutIds,
            $workflowCatalog,
            [$contextWidget],
        );
        $view['preference_forms'] = $preferenceForms->forms;
        $view['preference_diagnostics'] = $preferenceForms->diagnostics;
        $view['access_group_browser'] = $this->accessGroupBrowser(SurfaceArea::Portal, $preferenceForms);
        $view['workflow_browser'] = $this->workflowBrowser(SurfaceArea::Portal, $preferenceForms);
        $view['preference_action'] = $this->preferenceQuery->mutationAction(
            SurfaceArea::Portal,
            $preferenceQuery,
        );
        $this->applyPreferenceNotice(
            $view,
            $request->getQueryParams(),
            ($preferenceForms->accessGroupAdministration
                && ($preferenceQuery->search !== '' || $preferenceQuery->page > 1))
                || $preferenceQuery->workflowSearch !== ''
                || $preferenceQuery->workflowPage > 1,
        );

        return new HtmlResponse($this->renderer->render('home', [
            'active_navigation' => 'core.portal-home',
            'csrf' => $session->csrfToken,
            'dashboard' => $view,
        ], $session), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Add only closed, server-selected preference result notices to the view contract.
     *
     * @param   array<string, mixed>     $view           Dashboard view updated in place.
     * @param   array<array-key, mixed>  $query          Untrusted query values inspected only as exact flags.
     * @param   bool                     $browserActive  Whether validated group browse state is active.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function applyPreferenceNotice(array &$view, array $query, bool $browserActive): void
    {
        $view['preference_saved'] = ($query['dashboard-saved'] ?? null) === '1';
        $error = $query['dashboard-error'] ?? null;
        $view['preference_error'] = match ($error) {
            'conflict' => 'core.interface_standard.dashboard.conflict_notice',
            'invalid' => 'core.interface_standard.dashboard.invalid_notice',
            default => '',
        };
        $view['preference_open'] = $view['preference_saved']
            || $view['preference_error'] !== ''
            || $browserActive;
    }

    /**
     * Map typed paging state to fixed portal links for the shared protected component.
     *
     * @param   SurfaceArea                        $area        Fixed delivery area.
     * @param   DashboardPreferenceFormProjection  $projection  Typed form projection.
     *
     * @return  array<string, bool|int|string>  No-JavaScript access-group browser contract.
     *
     * @since   2.0.0
     */
    private function accessGroupBrowser(
        SurfaceArea $area,
        DashboardPreferenceFormProjection $projection,
    ): array {
        $query = $projection->accessGroupQuery;

        return [
            'available' => $projection->accessGroupAdministration,
            'active' => $query->search !== '' || $query->page > 1,
            'search' => $query->search,
            'page' => $query->page,
            'result_count' => $projection->accessGroupResultCount,
            'has_previous' => $projection->accessGroupHasPrevious,
            'has_next' => $projection->accessGroupHasNext,
            'browse_limit' => $projection->accessGroupBrowseLimit,
            'action' => $this->preferenceQuery->browseAction($area),
            'clear_href' => $this->preferenceQuery->browseHref(
                $area,
                $query->withoutAccessGroupBrowser(),
            ),
            'previous_href' => $projection->accessGroupHasPrevious
                ? $this->preferenceQuery->browseHref($area, $query->previous())
                : '',
            'next_href' => $projection->accessGroupHasNext
                ? $this->preferenceQuery->browseHref($area, $query->next())
                : '',
        ];
    }

    /**
     * Map the shared bounded workflow page to fixed portal no-JavaScript links.
     *
     * @param   SurfaceArea                        $area        Fixed delivery area.
     * @param   DashboardPreferenceFormProjection  $projection  Typed form and browser projection.
     *
     * @return  array<string, bool|int|string>  Independent workflow browser contract.
     *
     * @since   2.0.0
     */
    private function workflowBrowser(
        SurfaceArea $area,
        DashboardPreferenceFormProjection $projection,
    ): array {
        $page = $projection->workflowPage;
        if ($page === null) {
            return ['available' => false];
        }
        $query = $page->query;

        return [
            'available' => true,
            'active' => $query->workflowSearch !== '' || $query->workflowPage > 1,
            'search' => $query->workflowSearch,
            'page' => $query->workflowPage,
            'result_count' => count($page->candidates),
            'has_previous' => $page->hasPrevious(),
            'has_next' => $page->hasNext,
            'browse_limit' => $page->browseLimit,
            'action' => $this->preferenceQuery->browseAction($area),
            'clear_href' => $this->preferenceQuery->browseHref($area, $query->withoutWorkflowBrowser()),
            'previous_href' => $page->hasPrevious()
                ? $this->preferenceQuery->browseHref($area, $query->workflowPrevious())
                : '',
            'next_href' => $page->hasNext
                ? $this->preferenceQuery->browseHref($area, $query->workflowNext())
                : '',
        ];
    }
}
