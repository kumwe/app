<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Twig;

use Kumwe\CMS\Extension\Runtime\ActiveExtensionSet;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Presentation\ThemeSurface;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;

final readonly class IsolatedTwigEnvironmentFactory
{
    public function __construct(
        private ActiveExtensionSet $active,
        private string $coreTemplateRoot,
        private string $cacheRoot,
        private bool $production,
    ) {
    }

    public function site(?SiteContext $site = null): SiteTwigEnvironment
    {
        return new SiteTwigEnvironment(
            $this->surfaceLoader(
                ThemeSurface::Site,
                $this->coreTemplateRoot . '/site',
                $site ?? SiteContext::default(),
            ),
            $this->options($this->cacheRoot . '/site'),
        );
    }

    public function administrator(): AdministratorTwigEnvironment
    {
        return new AdministratorTwigEnvironment(
            $this->surfaceLoader(ThemeSurface::Administrator, $this->coreTemplateRoot . '/administrator'),
            $this->options($this->cacheRoot . '/administrator'),
        );
    }

    public function recoveryAdministrator(): RecoveryAdministratorTwigEnvironment
    {
        $loader = new FilesystemLoader();
        $loader->addPath($this->coreTemplateRoot . '/administrator');
        $loader->addPath($this->coreTemplateRoot . '/administrator', 'core-admin');

        return new RecoveryAdministratorTwigEnvironment(
            $loader,
            $this->options($this->cacheRoot . '/recovery-administrator'),
        );
    }

    private function surfaceLoader(ThemeSurface $surface, string $corePath, ?SiteContext $site = null): LoaderInterface
    {
        $themePath = $surface === ThemeSurface::Site
            ? $this->active->siteThemePath(($site ?? SiteContext::default())->identifier())
            : $this->active->themePath($surface);

        if ($surface === ThemeSurface::Administrator) {
            return $this->administratorLoader($corePath, $themePath);
        }

        $loader = new FilesystemLoader();

        if ($themePath !== null) {
            $loader->addPath($themePath);
            $loader->addPath($themePath, 'site-theme');
        }

        $loader->addPath($corePath);
        $loader->addPath($corePath, 'core-site');

        foreach ($this->active->extensionViewPaths($surface) as $identifier => $path) {
            $loader->addPath($path, self::extensionNamespace($identifier));
        }

        return $loader;
    }

    private function administratorLoader(string $corePath, ?string $themePath): LoaderInterface
    {
        $core = new FilesystemLoader();
        $core->addPath($corePath);
        $core->addPath($corePath, 'core-admin');
        foreach ($this->active->extensionViewPaths(ThemeSurface::Administrator) as $identifier => $path) {
            $core->addPath($path, self::extensionNamespace($identifier));
        }
        if ($themePath === null) {
            return $core;
        }

        $theme = new FilesystemLoader();
        $theme->addPath($themePath);
        $theme->addPath($themePath, 'admin-theme');

        return new ChainLoader([
            new ContractRestrictedLoader($theme, ['layout.twig', '@admin-theme/layout.twig']),
            $core,
        ]);
    }

    public static function extensionNamespace(string $identifier): string
    {
        return 'extension-' . bin2hex($identifier);
    }

    /** @return array{autoescape: string, cache: string|false, strict_variables: true} */
    private function options(string $cache): array
    {
        return [
            'autoescape' => 'html',
            'cache' => $this->production ? $cache : false,
            'strict_variables' => true,
        ];
    }
}
