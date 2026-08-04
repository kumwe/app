<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Joomla\DI\Container;
use Kumwe\CMS\Extension\Application\ExtensionServiceProvider;
use Mezzio\Application;

interface RuntimeExtension extends ExtensionServiceProvider
{
    /** Called once after every active provider has registered its services. */
    public function boot(Container $container): void;

    /** Register routes through Mezzio; route handlers must come from the container. */
    public function registerRoutes(Application $application): void;
}
