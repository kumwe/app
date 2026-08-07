<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use Kumwe\CMS\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;

final readonly class ExtensionContributionRegistrySet
{
    private CapabilityDefinitionRegistry $capabilities;

    private AdministratorWorkspaceRegistry $workspaces;

    private AdministratorNavigationRegistry $navigation;

    private AdministratorViewRegistry $views;

    private AdministratorRouteRegistry $routes;

    public function __construct(?TrustStore $trust = null, bool $withCore = true)
    {
        $this->capabilities = new CapabilityDefinitionRegistry();
        $this->workspaces = new AdministratorWorkspaceRegistry();
        $this->navigation = new AdministratorNavigationRegistry(
            $this->workspaces,
            $this->capabilities,
            $trust,
        );
        $this->views = new AdministratorViewRegistry();
        $this->routes = new AdministratorRouteRegistry($this->capabilities, $this->views);
        if ($withCore) {
            $registrar = $this->registrar(
                ContributionOwner::core(),
                new ManifestContributionSet(ContributionOwner::core()),
                false,
            );
            CoreExtensionContributions::register($registrar);
            $registrar->complete();
        }
    }

    public function registrar(
        ContributionOwner $owner,
        ManifestContributionSet $declared,
        bool $strict = true,
    ): OwnedExtensionContributionRegistrar {
        if ($declared->owner->identifier() !== $owner->identifier()) {
            throw new \InvalidArgumentException('Contribution declarations do not belong to this provider.');
        }
        return new OwnedExtensionContributionRegistrar($owner, $declared, $this, $strict);
    }

    public function capabilities(): CapabilityDefinitionRegistry
    {
        return $this->capabilities;
    }

    public function workspaces(): AdministratorWorkspaceRegistry
    {
        return $this->workspaces;
    }

    public function navigation(): AdministratorNavigationRegistry
    {
        return $this->navigation;
    }

    public function views(): AdministratorViewRegistry
    {
        return $this->views;
    }

    public function routes(): AdministratorRouteRegistry
    {
        return $this->routes;
    }

    /** @return array<string, mixed> */
    public function inventory(ContributionOwner $owner): array
    {
        return [
            'capabilities' => $this->capabilities->ownedBy($owner),
            'administrator' => [
                'workspaces' => $this->workspaces->ownedBy($owner),
                'navigation' => $this->navigation->ownedBy($owner),
                'routes' => $this->routes->ownedBy($owner),
                'views' => $this->views->ownedBy($owner),
            ],
        ];
    }

    public function remove(ContributionOwner $owner): void
    {
        $this->routes->remove($owner);
        $this->navigation->remove($owner);
        $this->views->remove($owner);
        $this->workspaces->remove($owner);
        $this->capabilities->remove($owner);
    }
}
