<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Kumwe\CMS\Extension\Application\ExtensionServiceProvider;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionProvider;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Presentation\ThemeSurface;
use LogicException;
use Mezzio\Application;

final class ActiveExtensionSet
{
    /**
     * @var list<array{
     *     identifier: string,
     *     provider: ExtensionServiceProvider,
     *     container: ExtensionContainer,
     *     declared: ManifestContributionSet,
     *     strict: bool
     * }>
     */
    private array $extensions = [];

    /** @var array<string, string> */
    private array $themePaths = [];

    /** @var array<string, string> */
    private array $siteThemePaths = [];

    public function __construct(
        private readonly ExtensionContributionRegistrySet $contributions,
        private ?TrustStore $trust = null,
    ) {
    }

    public function add(
        string $identifier,
        ExtensionServiceProvider $provider,
        ExtensionContainer $container,
        ManifestContributionSet $declared,
        bool $strictContributions,
    ): void {
        $this->extensions[] = [
            'identifier' => $identifier,
            'provider' => $provider,
            'container' => $container,
            'declared' => $declared,
            'strict' => $strictContributions,
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

    /** Runs after every provider registered services and before boot or route registration. */
    public function contribute(): void
    {
        foreach ($this->extensions as $extension) {
            $provider = $extension['provider'];
            if (!$provider instanceof ExtensionContributionProvider) {
                if ($extension['strict']) {
                    throw new LogicException(sprintf(
                        'Schema-2 extension %s must implement the contribution provider contract.',
                        $extension['identifier'],
                    ));
                }
                continue;
            }
            if (!$extension['strict']) {
                throw new LogicException(sprintf(
                    'Extension %s must use manifest schema 2 before contributing runtime surfaces.',
                    $extension['identifier'],
                ));
            }
            $registrar = $this->contributions->registrar(
                ContributionOwner::extension($extension['identifier']),
                $extension['declared'],
            );
            $provider->contribute($registrar, $extension['container']);
            $registrar->complete();
        }
        $this->contributions->validateBusinessDefinitions();
    }

    public function registerRoutes(Application $application, AdministratorRenderer $renderer): void
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
        $trust = $this->trust
            ?? throw new LogicException('Administrator extension routes require an installed trust boundary.');
        $this->contributions->routes()->registerInto($application, $trust, $renderer);
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

    /** @return array<string, mixed> */
    public function contributionInventory(string $identifier): array
    {
        return $this->contributions->inventory(ContributionOwner::extension($identifier));
    }
}
