<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Contract for building the request handler behind one contributed administrator route.
 *
 * A provider declares its routes while its own container is still being wired, before the
 * application is routed and without reach into the administrator renderer, so it contributes this
 * factory rather than a finished handler. `AdministratorRouteRegistry::registerInto()` calls it as
 * it mounts each route and wraps the result in the extension trust boundary, so an implementation
 * runs at wiring time and never on the request path.
 *
 * @since  2.0.0
 */
interface AdministratorRouteHandlerFactory
{
    /**
     * Build the handler that serves the contributed route.
     *
     * The renderer is supplied rather than constructed, so an extension page renders through the
     * same layout, navigation, capability filter, and asset pipeline as a core administrator page.
     *
     * @param   AdministratorRenderer  $renderer  Shared administrator renderer the handler renders its view with.
     *
     * @return  RequestHandlerInterface  The route's own handler, before trust enforcement wraps it.
     *
     * @since   2.0.0
     */
    public function create(AdministratorRenderer $renderer): RequestHandlerInterface;
}
