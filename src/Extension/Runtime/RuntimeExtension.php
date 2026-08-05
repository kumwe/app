<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Kumwe\CMS\Extension\Application\ExtensionServiceProvider;

interface RuntimeExtension extends ExtensionServiceProvider
{
    /** Called once after every active provider has registered its services. */
    public function boot(ExtensionContainer $container): void;

    public function registerRoutes(ExtensionRouteRegistrar $routes): void;
}
