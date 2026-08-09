<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Kumwe\CMS\Extension\Application\ExtensionServiceProvider;

/**
 * Contract for an extension that takes part in the request runtime, not only in service registration.
 *
 * `ExtensionServiceProvider::register()` runs while the container is still being filled, so a provider
 * cannot resolve a collaborator that a later extension has yet to register. Implementing this
 * interface opts into the two phases `ActiveExtensionSet` drives afterwards: `boot()` once every
 * active provider has registered, and `registerRoutes()` when the HTTP application declares routes.
 * An extension that only publishes services can stay on the smaller provider contract.
 *
 * @since  2.0.0
 */
interface RuntimeExtension extends ExtensionServiceProvider
{
    /**
     * Wire up behaviour that depends on services other extensions registered.
     *
     * Called once per process, after every active provider has registered its services and before any
     * route is declared, so the container is complete by the time it runs.
     *
     * @param   ExtensionContainer  $container  This extension's restricted container, holding the host
     *          services it was allowlisted and the ones it shared itself.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function boot(ExtensionContainer $container): void;

    /**
     * Declare the HTTP routes this extension serves.
     *
     * The registrar confines each route to the extension's own path and route-name namespace and wraps
     * the handler in a trust check, so a route declared here stops answering as soon as the extension
     * is disabled or loses trust, without the router having to be rebuilt.
     *
     * @param   ExtensionRouteRegistrar  $routes  Namespaced registrar to declare the routes on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function registerRoutes(ExtensionRouteRegistrar $routes): void;
}
