<?php

declare(strict_types=1);

namespace Kumwe\CMS\Portal\Contribution;

use Kumwe\CMS\Portal\Presentation\PortalContributionRenderer;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Factory contract for a contributed route served through the shared portal renderer.
 *
 * @since  2.0.0
 */
interface PortalRouteHandlerFactory
{
    /**
     * Build the route handler before live trust and CSRF wrappers are applied.
     *
     * @param   PortalContributionRenderer  $renderer  Owner-and-template-bound rendering capability.
     *
     * @return  RequestHandlerInterface  Extension handler.
     *
     * @since   2.0.0
     */
    public function create(PortalContributionRenderer $renderer): RequestHandlerInterface;
}
