<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Http\Handler;

use InvalidArgumentException;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceService;
use Kumwe\App\Application\Presentation\Preference\PresentationPreferenceVersionConflict;
use Kumwe\App\Delivery\Http\Dashboard\DashboardPreferenceFormDecoder;
use Kumwe\App\Delivery\Http\Dashboard\DashboardPreferenceQueryDecoder;
use Kumwe\Contribution\ContributionOwner;
use Kumwe\InterfaceStandard\SurfaceArea;
use Kumwe\InterfaceStandard\SurfaceId;
use Kumwe\App\Portal\Http\PortalRequest;
use Kumwe\App\Portal\Presentation\PortalRenderer;
use Kumwe\App\Presentation\Application\Dashboard\DashboardWorkflowCatalog;
use Laminas\Diactoros\Response\RedirectResponse;
use Kumwe\InterfaceStandard\CustomizationScope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Delivers portal dashboard preference mutations against the live session-visible widget catalog.
 *
 * @since  2.0.0
 */
final readonly class PortalDashboardPreferencesHandler implements RequestHandlerInterface
{
    /**
     * Bind strict dashboard preference delivery to the portal navigation projection.
     *
     * @param  DashboardPreferenceService       $preferences  Shared KIS application mutation boundary.
     * @param  DashboardPreferenceFormDecoder   $decoder      Browser form to typed command translation.
     * @param  DashboardPreferenceQueryDecoder  $query        Validated same-area continuation codec.
     * @param  PortalRenderer                   $renderer     Session-filtered live navigation projection.
     *
     * @since  2.0.0
     */
    public function __construct(
        private DashboardPreferenceService $preferences,
        private DashboardPreferenceFormDecoder $decoder,
        private DashboardPreferenceQueryDecoder $query,
        private PortalRenderer $renderer,
    ) {
    }

    /**
     * Save or reset one personal or access-group dashboard preference and redirect to its form notice.
     *
     * @param   ServerRequestInterface  $request  Authenticated, authorized, and CSRF-checked portal request.
     *
     * @return  ResponseInterface  No-store 303 redirect carrying only a closed result code.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = PortalRequest::session($request);
        $context = PortalRequest::context($request);
        $capabilities = PortalRequest::capabilityMap($request);
        $query = $this->query->decode($request->getQueryParams());
        $catalog = new DashboardWorkflowCatalog(
            SurfaceArea::Portal,
            SurfaceId::fromString('core.portal.home'),
            $this->renderer->visibleNavigation($session),
        );
        $coreWidgets = [];
        if (isset($capabilities['portal.access'])) {
            $coreWidgets[] = 'core.dashboard.access-context';
        }

        try {
            $mutation = $this->decoder->decode(PortalRequest::form($request));
            if ($mutation->scope === CustomizationScope::User) {
                $catalog->assertMutation($mutation, $coreWidgets);
            }
            // An access-group form may carry identifiers the role stores but this editor cannot see; only
            // the editor-visible subset is offered, and the service admits the rest solely from the stored row.
            $admitted = $catalog->admitted($mutation, $coreWidgets);
            $this->preferences->mutate(
                $context,
                SurfaceArea::Portal,
                SurfaceId::fromString('core.portal.home'),
                ContributionOwner::core(),
                $mutation,
                $admitted,
                $admitted,
            );
        } catch (PresentationPreferenceVersionConflict) {
            return new RedirectResponse(
                $this->query->errorHref(SurfaceArea::Portal, $query, 'conflict'),
                303,
                ['Cache-Control' => 'no-store'],
            );
        } catch (InvalidArgumentException) {
            return new RedirectResponse(
                $this->query->errorHref(SurfaceArea::Portal, $query, 'invalid'),
                303,
                ['Cache-Control' => 'no-store'],
            );
        }

        return new RedirectResponse(
            $this->query->successHref(SurfaceArea::Portal, $query),
            303,
            ['Cache-Control' => 'no-store'],
        );
    }
}
