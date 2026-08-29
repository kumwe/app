<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceService;
use Kumwe\App\Application\Presentation\Preference\PresentationPreferenceVersionConflict;
use Kumwe\App\Delivery\Http\Dashboard\DashboardPreferenceFormDecoder;
use Kumwe\App\Delivery\Http\Dashboard\DashboardPreferenceQueryDecoder;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\InterfaceStandard\SurfaceArea;
use Kumwe\App\InterfaceStandard\SurfaceId;
use Kumwe\App\Presentation\Application\Dashboard\DashboardWorkflowCatalog;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Delivers administrator dashboard preference mutations against the live visible widget catalog.
 *
 * @since  2.0.0
 */
final readonly class AdministratorDashboardPreferencesHandler implements RequestHandlerInterface
{
    /**
     * Bind strict dashboard preference delivery to the administrator navigation projection.
     *
     * @param  DashboardPreferenceService       $preferences  Shared KIS application mutation boundary.
     * @param  DashboardPreferenceFormDecoder   $decoder      Browser form to typed command translation.
     * @param  DashboardPreferenceQueryDecoder  $query        Validated same-area continuation codec.
     * @param  AdministratorRenderer            $renderer     Capability-filtered live navigation projection.
     *
     * @since  2.0.0
     */
    public function __construct(
        private DashboardPreferenceService $preferences,
        private DashboardPreferenceFormDecoder $decoder,
        private DashboardPreferenceQueryDecoder $query,
        private AdministratorRenderer $renderer,
    ) {
    }

    /**
     * Save or reset one personal or access-group dashboard preference and redirect to its form notice.
     *
     * @param   ServerRequestInterface  $request  Authenticated, authorized, and CSRF-checked request.
     *
     * @return  ResponseInterface  No-store 303 redirect carrying only a closed result code.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $context = AdministratorRequest::context($request);
        $capabilities = AdministratorRequest::capabilityMap($request);
        $query = $this->query->decode($request->getQueryParams());
        $catalog = new DashboardWorkflowCatalog(
            SurfaceArea::Administrator,
            SurfaceId::fromString('core.administrator.dashboard'),
            $this->renderer->visibleNavigation($capabilities),
        );
        $coreWidgets = ['core.dashboard.administrator-context'];
        if (isset($capabilities['content.read'])) {
            array_unshift(
                $coreWidgets,
                'core.dashboard.content-summary',
                'core.dashboard.recent-content',
            );
        }

        try {
            $mutation = $this->decoder->decode(AdministratorRequest::form($request));
            $catalog->assertMutation($mutation, $coreWidgets);
            $this->preferences->mutate(
                $context,
                SurfaceArea::Administrator,
                SurfaceId::fromString('core.administrator.dashboard'),
                ContributionOwner::core(),
                $mutation,
                $mutation->submittedIds,
                $mutation->submittedIds,
            );
        } catch (PresentationPreferenceVersionConflict) {
            return new RedirectResponse(
                $this->query->errorHref(SurfaceArea::Administrator, $query, 'conflict'),
                303,
                ['Cache-Control' => 'no-store'],
            );
        } catch (InvalidArgumentException) {
            return new RedirectResponse(
                $this->query->errorHref(SurfaceArea::Administrator, $query, 'invalid'),
                303,
                ['Cache-Control' => 'no-store'],
            );
        }

        return new RedirectResponse(
            $this->query->successHref(SurfaceArea::Administrator, $query),
            303,
            ['Cache-Control' => 'no-store'],
        );
    }
}
