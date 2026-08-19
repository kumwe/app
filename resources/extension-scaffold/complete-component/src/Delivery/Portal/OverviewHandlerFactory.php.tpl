<?php

declare(strict_types=1);

namespace @@PHP_NAMESPACE@@\Delivery\Portal;

use @@PHP_NAMESPACE@@\Application\OverviewService;
use Kumwe\App\Portal\Contribution\PortalRouteHandlerFactory;
use Kumwe\App\Portal\Presentation\PortalContributionRenderer;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Builds the portal handler with the renderer capability granted by the registry.
 *
 * @since  2.0.0
 */
final readonly class OverviewHandlerFactory implements PortalRouteHandlerFactory
{
    /**
     * Bind handler construction to the component application service.
     *
     * @param  OverviewService  $overview  Transport-neutral component service.
     *
     * @since  2.0.0
     */
    public function __construct(private OverviewService $overview)
    {
    }

    /**
     * Build a handler using the object-capability renderer granted by the route registry.
     *
     * @param   PortalContributionRenderer  $renderer  Owner-and-template-bound portal renderer.
     *
     * @return  RequestHandlerInterface  Ready portal overview handler.
     *
     * @since   2.0.0
     */
    public function create(PortalContributionRenderer $renderer): RequestHandlerInterface
    {
        return new OverviewHandler($this->overview, $renderer);
    }
}
