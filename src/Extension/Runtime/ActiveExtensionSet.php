<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Kumwe\CMS\Extension\Application\ExtensionServiceProvider;
use Mezzio\Application;

final class ActiveExtensionSet
{
    /** @var list<array{identifier: string, provider: ExtensionServiceProvider, container: ExtensionContainer}> */
    private array $extensions = [];

    /** @var list<string> */
    private array $templatePaths = [];

    public function add(
        string $identifier,
        ExtensionServiceProvider $provider,
        ExtensionContainer $container,
        ?string $templatePath = null,
    ): void {
        $this->extensions[] = [
            'identifier' => $identifier,
            'provider' => $provider,
            'container' => $container,
        ];

        if ($templatePath !== null) {
            $this->templatePaths[] = $templatePath;
        }
    }

    public function boot(): void
    {
        foreach ($this->extensions as $extension) {
            if ($extension['provider'] instanceof RuntimeExtension) {
                $extension['provider']->boot($extension['container']);
            }
        }
    }

    public function registerRoutes(Application $application): void
    {
        foreach ($this->extensions as $extension) {
            if ($extension['provider'] instanceof RuntimeExtension) {
                $extension['provider']->registerRoutes(new MezzioExtensionRouteRegistrar(
                    $application,
                    $extension['identifier'],
                ));
            }
        }
    }

    public function count(): int
    {
        return count($this->extensions);
    }

    /** @return list<string> */
    public function templatePaths(): array
    {
        return $this->templatePaths;
    }
}
