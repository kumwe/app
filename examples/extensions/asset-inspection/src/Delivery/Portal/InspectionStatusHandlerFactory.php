<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Delivery\Portal;

use Kumwe\App\Portal\Contribution\PortalRouteHandlerFactory;
use Kumwe\App\Portal\Presentation\PortalContributionRenderer;
use KumweExample\AssetInspection\Application\InspectionOverviewService;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Builds the portal handler with the object-capability renderer granted by the registry.
 *
 * @since  2.0.0
 */
final readonly class InspectionStatusHandlerFactory implements PortalRouteHandlerFactory
{
    /**
     * Bind handler construction to the shared overview use case.
     *
     * @param  InspectionOverviewService  $overview  Transport-neutral policy-filtered service.
     *
     * @since  2.0.0
     */
    public function __construct(private InspectionOverviewService $overview)
    {
    }

    /**
     * Create the contributed route handler with its fixed portal renderer capability.
     *
     * @param   PortalContributionRenderer  $renderer  Owner-and-template-bound renderer.
     *
     * @return  RequestHandlerInterface  Ready read-only portal handler.
     *
     * @since   2.0.0
     */
    public function create(PortalContributionRenderer $renderer): RequestHandlerInterface
    {
        return new InspectionStatusHandler($this->overview, $renderer);
    }
}
