<?php

declare(strict_types=1);

namespace Kumwe\CMS\Portal\Http\Handler;

use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\SurfaceArea;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use Kumwe\CMS\Portal\Http\PortalRequest;
use Kumwe\CMS\Portal\Presentation\PortalRenderer;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardComposer;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardPreferenceService;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardWidget;
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
     * @param  PortalRenderer     $renderer   Portal isolation, policy-filtered navigation, and rendering boundary.
     * @param  DashboardComposer  $dashboard  Shared KIS widget, access-group, and personal preference composer.
     * @param  DashboardPreferenceService $preferences Builds authorized personal and access-group controls.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PortalRenderer $renderer,
        private DashboardComposer $dashboard,
        private DashboardPreferenceService $preferences,
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
        $workflowIds = DashboardComposer::workflowIdentifiers(
            SurfaceArea::Portal,
            $surface,
            $navigation,
        );
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
        );
        $view = $dashboard->toArray();
        $view['preference_forms'] = $this->preferences->formModels(
            $context,
            SurfaceArea::Portal,
            $surface,
            ContributionOwner::core(),
            isset($capabilities['users.manage']),
            $dashboard->selectedWidgetIds,
            $dashboard->selectedShortcutIds,
        );
        $view['preference_action'] = '/portal/dashboard/preferences';
        $this->applyPreferenceNotice($view, $request->getQueryParams());

        return new HtmlResponse($this->renderer->render('home', [
            'active_navigation' => 'core.portal-home',
            'csrf' => $session->csrfToken,
            'dashboard' => $view,
        ], $session), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Add only closed, server-selected preference result notices to the view contract.
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
