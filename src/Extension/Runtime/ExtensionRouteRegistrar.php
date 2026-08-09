<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route-declaration surface an extension is handed while the HTTP application is being composed.
 *
 * An extension declares its routes through this contract instead of touching the router, which is what
 * makes the declarations containable: an implementation is bound to one extension and keeps every route
 * it accepts inside that extension's path and route-name namespace, so no extension can shadow a core
 * path or claim another extension's route name. Routes are declared once at composition and are never
 * withdrawn, so an implementation is also expected to make each route revocable per request rather than
 * at registration time. `MezzioExtensionRouteRegistrar` is what the runtime passes to
 * `RuntimeExtension::registerRoutes()`.
 *
 * @since  2.0.0
 */
interface ExtensionRouteRegistrar
{
    /**
     * Declare one route for the owning extension.
     *
     * @param   string                   $path     Request path to answer on, inside the extension's path
     *          namespace.
     * @param   RequestHandlerInterface  $handler  Handler invoked for a matching request.
     * @param   non-empty-list<string>   $methods  Upper-case HTTP methods the route accepts.
     * @param   string                   $name     Route name used to generate URLs, inside the extension's
     *          route-name namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function route(
        string $path,
        RequestHandlerInterface $handler,
        array $methods,
        string $name,
    ): void;
}
