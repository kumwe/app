<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Kumwe\CMS\Extension\Application\ExtensionServiceProvider;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Presentation\ThemeSurface;
use LogicException;
use Mezzio\Application;

final class ActiveExtensionSet
{
    /** @var list<array{identifier: string, provider: ExtensionServiceProvider, container: ExtensionContainer}> */
    private array $extensions = [];

    /** @var array<string, string> */
    private array $themePaths = [];

    /** @var array<string, string> */
    private array $siteThemePaths = [];

    public function __construct(private ?TrustStore $trust = null)
    {
    }

    public function add(
        string $identifier,
        ExtensionServiceProvider $provider,
        ExtensionContainer $container,
    ): void {
        $this->extensions[] = [
            'identifier' => $identifier,
            'provider' => $provider,
            'container' => $container,
        ];
    }

    /** @var array<string, array<string, string>> */
    private array $extensionViewPaths = [
        'site' => [],
        'administrator' => [],
    ];

    public function setThemePath(ThemeSurface $surface, string $path): void
    {
        if (isset($this->themePaths[$surface->value])) {
            throw new LogicException(sprintf('More than one %s theme was loaded.', $surface->value));
        }

        $this->themePaths[$surface->value] = $path;
    }

    public function addExtensionViewPath(ThemeSurface $surface, string $identifier, string $path): void
    {
        $this->extensionViewPaths[$surface->value][$identifier] = $path;
    }

    public function setSiteThemePath(string $siteIdentifier, string $path): void
    {
        if (isset($this->siteThemePaths[$siteIdentifier])) {
            throw new LogicException(sprintf('More than one theme was loaded for site %s.', $siteIdentifier));
        }
        $this->siteThemePaths[$siteIdentifier] = $path;
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
                $trust = $this->trust
                    ?? throw new LogicException('Runtime extensions require an installed trust boundary.');
                // The immutable, signed runtime publication is the request-bootstrap
                // trust attestation. Route handlers are still wrapped in live trust
                // enforcement by the registrar, but ordinary application bootstrap
                // must not acquire the global lifecycle lock or hash the runtime tree.
                $extension['provider']->registerRoutes(new MezzioExtensionRouteRegistrar(
                    $application,
                    $extension['identifier'],
                    $trust,
                ));
            }
        }
    }

    public function count(): int
    {
        return count($this->extensions);
    }

    public function themePath(ThemeSurface $surface): ?string
    {
        return $this->themePaths[$surface->value] ?? null;
    }

    public function siteThemePath(string $siteIdentifier): ?string
    {
        return $this->siteThemePaths[$siteIdentifier] ?? null;
    }

    /** @return array<string, string> */
    public function extensionViewPaths(ThemeSurface $surface): array
    {
        return $this->extensionViewPaths[$surface->value];
    }
}
