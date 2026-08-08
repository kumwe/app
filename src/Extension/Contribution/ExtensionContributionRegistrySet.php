<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use Kumwe\CMS\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionContributionRegistry;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;

final readonly class ExtensionContributionRegistrySet
{
    private CapabilityDefinitionRegistry $capabilities;

    private AdministratorWorkspaceRegistry $workspaces;

    private AdministratorNavigationRegistry $navigation;

    private AdministratorViewRegistry $views;

    private AdministratorRouteRegistry $routes;

    private FieldTypeRegistry $fieldTypes;

    private BusinessDefinitionContributionRegistry $businessDefinitions;

    /**
     * Every contribution kind, keyed by its dotted inventory path.
     *
     * Inventory and lifecycle removal both derive from this map, so a new kind becomes
     * discoverable and removable by being declared once. Removal order is the reverse of
     * declaration order: dependents are withdrawn before what they depend on.
     *
     * @var array<string, ContributionSurface>
     */
    private array $surfaces;

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
        $this->fieldTypes = new FieldTypeRegistry(false);
        $this->businessDefinitions = new BusinessDefinitionContributionRegistry(
            new BusinessDefinitionValidator($this->fieldTypes),
        );
        $this->surfaces = [
            'capabilities' => $this->capabilities,
            'administrator.workspaces' => $this->workspaces,
            'administrator.navigation' => $this->navigation,
            'administrator.routes' => $this->routes,
            'administrator.views' => $this->views,
            'business.field_types' => BusinessContributionSurface::forFieldTypes($this->fieldTypes),
            'business.definitions' => BusinessContributionSurface::forDefinitions($this->businessDefinitions),
        ];
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

    public function fieldTypes(): FieldTypeRegistry
    {
        return $this->fieldTypes;
    }

    public function businessDefinitions(): BusinessDefinitionContributionRegistry
    {
        return $this->businessDefinitions;
    }

    public function validateBusinessDefinitions(): void
    {
        $this->businessDefinitions->validate();
    }

    /**
     * The declared contribution kinds, in declaration order.
     *
     * @return list<string>
     */
    public function surfaceKeys(): array
    {
        return array_keys($this->surfaces);
    }

    /** @return array<string, mixed> */
    public function inventory(ContributionOwner $owner): array
    {
        /** @var array<string, mixed> $inventory */
        $inventory = [];
        /** @var array<string, array<string, list<mixed>>> $grouped */
        $grouped = [];
        foreach ($this->surfaces as $key => $surface) {
            $contributions = $surface->ownedBy($owner);
            $separator = strpos($key, '.');
            if ($separator === false) {
                $inventory[$key] = $contributions;
                continue;
            }
            $group = substr($key, 0, $separator);
            $grouped[$group][substr($key, $separator + 1)] = $contributions;
        }
        foreach ($grouped as $group => $entries) {
            $inventory[$group] = $entries;
        }

        return $inventory;
    }

    public function remove(ContributionOwner $owner): void
    {
        foreach (array_reverse($this->surfaces) as $surface) {
            $surface->remove($owner);
        }
    }
}
