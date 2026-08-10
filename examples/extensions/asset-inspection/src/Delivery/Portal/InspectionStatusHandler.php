<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Delivery\Portal;

use Kumwe\CMS\Portal\Http\PortalRequest;
use Kumwe\CMS\Portal\Presentation\PortalContributionRenderer;
use KumweExample\AssetInspection\Application\InspectionOverviewService;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Renders the explicitly contributed read-only portal status page.
 *
 * @since  2.0.0
 */
final readonly class InspectionStatusHandler implements RequestHandlerInterface
{
    /**
     * Bind portal request adaptation to shared policy and an owner-bound renderer capability.
     *
     * @param  InspectionOverviewService   $overview  Transport-neutral proof use case.
     * @param  PortalContributionRenderer  $renderer  Renderer fixed to this owner and template.
     *
     * @since  2.0.0
     */
    public function __construct(
        private InspectionOverviewService $overview,
        private PortalContributionRenderer $renderer,
    ) {
    }

    /**
     * Render site-filtered data from an authenticated portal request without restricted fields.
     *
     * @param   ServerRequestInterface  $request  Request carrying portal session and context.
     *
     * @return  ResponseInterface  Non-cacheable portal proof page.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = PortalRequest::session($request);
        $model = $this->overview->portal(PortalRequest::context($request));

        return new HtmlResponse($this->renderer->render(
            $model + [
                'capabilities' => PortalRequest::capabilityMap($request),
                'active_navigation' => 'kumwe.asset-inspection-example.portal.navigation',
            ],
            $session,
        ), 200, ['Cache-Control' => 'no-store']);
    }
}
