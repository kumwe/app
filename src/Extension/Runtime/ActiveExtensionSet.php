<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Joomla\DI\Container;
use Kumwe\CMS\Extension\Application\ExtensionServiceProvider;
use Mezzio\Application;

final class ActiveExtensionSet
{
    /** @var list<ExtensionServiceProvider> */
    private array $providers = [];

    /** @var list<string> */
    private array $templatePaths = [];

    public function add(ExtensionServiceProvider $provider, ?string $templatePath = null): void
    {
        $this->providers[] = $provider;

        if ($templatePath !== null) {
            $this->templatePaths[] = $templatePath;
        }
    }

    public function boot(Container $container): void
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof RuntimeExtension) {
                $provider->boot($container);
            }
        }
    }

    public function registerRoutes(Application $application): void
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof RuntimeExtension) {
                $provider->registerRoutes($application);
            }
        }
    }

    public function count(): int
    {
        return count($this->providers);
    }

    /** @return list<string> */
    public function templatePaths(): array
    {
        return $this->templatePaths;
    }
}
