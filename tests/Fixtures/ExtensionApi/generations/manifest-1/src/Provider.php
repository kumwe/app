<?php

declare(strict_types=1);

namespace KumweContract\ManifestOne;

use Kumwe\CMS\Extension\Runtime\ExtensionContainer;
use Kumwe\CMS\Extension\Runtime\ExtensionRouteRegistrar;
use Kumwe\CMS\Extension\Runtime\RuntimeExtension;

/**
 * Compatibility provider for the manifest-1 generation of the extension contract.
 *
 * Schema 1 predates typed contributions. Its whole promise is that a package may register its own
 * services, boot, and declare its own routes — and that it contributes nothing to the shared
 * contribution registries. The lifecycle fixture asserts exactly that, so a package still on schema 1
 * keeps installing and activating rather than being quietly reinterpreted as a strict one.
 *
 * @since  2.0.0
 */
final class Provider implements RuntimeExtension
{
    /**
     * Identifier the package's own shared service is registered under.
     *
     * @var    string
     * @since  2.0.0
     */
    public const GREETING = 'extension.kumwe.contract-manifest-one.greeting';

    /**
     * Register the package's own namespaced service through the restricted container.
     *
     * @param   ExtensionContainer  $container  Restricted owner-scoped service surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function register(ExtensionContainer $container): void
    {
        $container->share(self::GREETING, static fn (ExtensionContainer $container): Greeting => new Greeting());
    }

    /**
     * Resolve the package's own service once the container is closed to further registration.
     *
     * @param   ExtensionContainer  $container  Restricted owner-scoped service surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function boot(ExtensionContainer $container): void
    {
        $service = $container->get(self::GREETING);
        if ($service instanceof Greeting) {
            $service->boot();
        }
    }

    /**
     * Declare the package's own routes; this generation's fixture declares none.
     *
     * @param   ExtensionRouteRegistrar  $routes  Registrar confined to this package's path namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function registerRoutes(ExtensionRouteRegistrar $routes): void
    {
    }
}
