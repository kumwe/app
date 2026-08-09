<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use Kumwe\CMS\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionContributionRegistry;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;

/**
 * The one place every contribution registry in a process is created, wired together, and reached.
 *
 * The registries depend on each other — navigation resolves workspaces and capabilities, routes
 * resolve capabilities and views, business definitions resolve field types — so composing them
 * separately would let a caller assemble a half-wired shell. Building them here as a set fixes that
 * wiring once and gives inventory and lifecycle removal a single declared list of surfaces to walk.
 * The accessors exist for application composition, which shares the registries into the container; an
 * extension never reaches them, only the owner-bound registrar `registrar()` hands out. Core is filled
 * in through that same registrar while the set is being built, so the CMS has no privileged path in.
 *
 * @since  2.0.0
 */
final readonly class ExtensionContributionRegistrySet
{
    /**
     * The site-wide permission vocabulary every guarded surface names a code from.
     *
     * @var    CapabilityDefinitionRegistry
     * @since  2.0.0
     */
    private CapabilityDefinitionRegistry $capabilities;

    /**
     * The top-level groupings administrator navigation items are filed under.
     *
     * @var    AdministratorWorkspaceRegistry
     * @since  2.0.0
     */
    private AdministratorWorkspaceRegistry $workspaces;

    /**
     * The administrator navigation items, filtered on presentation by capability and live trust.
     *
     * @var    AdministratorNavigationRegistry
     * @since  2.0.0
     */
    private AdministratorNavigationRegistry $navigation;

    /**
     * The named templates contributed administrator routes are allowed to render.
     *
     * @var    AdministratorViewRegistry
     * @since  2.0.0
     */
    private AdministratorViewRegistry $views;

    /**
     * The contributed administrator routes, mounted into the application after the contribution phase.
     *
     * @var    AdministratorRouteRegistry
     * @since  2.0.0
     */
    private AdministratorRouteRegistry $routes;

    /**
     * The field types business definitions may build fields from, seeded by core rather than by itself.
     *
     * @var    FieldTypeRegistry
     * @since  2.0.0
     */
    private FieldTypeRegistry $fieldTypes;

    /**
     * The entity types contributed this process, validated as one graph once every provider has run.
     *
     * @var    BusinessDefinitionContributionRegistry
     * @since  2.0.0
     */
    private BusinessDefinitionContributionRegistry $businessDefinitions;

    /**
     * Every contribution kind, keyed by its dotted inventory path.
     *
     * Inventory and lifecycle removal both derive from this map, so a new kind becomes
     * discoverable and removable by being declared once. Removal order is the reverse of
     * declaration order: dependents are withdrawn before what they depend on.
     *
     * @var    array<string, ContributionSurface>
     * @since  2.0.0
     */
    private array $surfaces;

    /**
     * Build the whole set of registries, wired to each other and populated with the core surface.
     *
     * Core is registered through a non-strict registrar, since it has no manifest to be reconciled
     * against; the recovery path builds a second, core-only set this way when a contributed page has
     * broken the normal render. Suppressing core leaves a wholly empty set, which is what a test
     * isolating one extension's contributions wants.
     *
     * @param  ?TrustStore  $trust     Source of live trust used to hide navigation whose owner is no longer
     *         trusted and active; null skips that filtering entirely.
     * @param  bool         $withCore  Whether to register the shipped core contributions while building.
     *
     * @since  2.0.0
     */
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

    /**
     * Open a contribution phase for one owner and hand back the only handle it gets on these registries.
     *
     * The declaration set must belong to the owner being served, which is what stops one package's
     * manifest being used to authorise another package's registrations. Each call opens an independent
     * phase, so a second registrar for the same owner starts with an empty seen-set and its
     * registrations collide inside the underlying registries rather than being reported here.
     *
     * @param   ContributionOwner        $owner     Contributor the returned registrar is permanently bound to.
     * @param   ManifestContributionSet  $declared  That owner's manifest declarations to reconcile against.
     * @param   bool                     $strict    False accepts undeclared and skips omitted contributions,
     *          as core and schema-1 packages require.
     *
     * @return  OwnedExtensionContributionRegistrar  A registrar valid until its `complete()` closes the phase.
     *
     * @throws  \InvalidArgumentException  When the declarations belong to a different owner.
     *
     * @since   2.0.0
     */
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

    /**
     * Reach the capability catalog the registrar writes contributed permission codes into.
     *
     * @return  CapabilityDefinitionRegistry  The live registry, holding core and contributed codes alike,
     *          and the authority on which of them exist.
     *
     * @since   2.0.0
     */
    public function capabilities(): CapabilityDefinitionRegistry
    {
        return $this->capabilities;
    }

    /**
     * Reach the workspace registry, which navigation resolves each item's grouping and ordering from.
     *
     * @return  AdministratorWorkspaceRegistry  The live registry shared with the navigation registry.
     *
     * @since   2.0.0
     */
    public function workspaces(): AdministratorWorkspaceRegistry
    {
        return $this->workspaces;
    }

    /**
     * Reach the navigation registry the administrator shell renders its menu from.
     *
     * @return  AdministratorNavigationRegistry  The live registry, already wired to workspaces, capabilities
     *          and trust.
     *
     * @since   2.0.0
     */
    public function navigation(): AdministratorNavigationRegistry
    {
        return $this->navigation;
    }

    /**
     * Reach the view registry, which route handlers resolve a contributed template through.
     *
     * @return  AdministratorViewRegistry  The live registry shared with the route registry.
     *
     * @since   2.0.0
     */
    public function views(): AdministratorViewRegistry
    {
        return $this->views;
    }

    /**
     * Reach the route registry, whose `registerInto()` mounts the contributed routes once wiring is done.
     *
     * @return  AdministratorRouteRegistry  The live registry, already wired to capabilities and views.
     *
     * @since   2.0.0
     */
    public function routes(): AdministratorRouteRegistry
    {
        return $this->routes;
    }

    /**
     * Reach the field-type registry, which is also the process-wide resolver for field references.
     *
     * @return  FieldTypeRegistry  The live registry; core built-ins arrive as contributions, not by seeding.
     *
     * @since   2.0.0
     */
    public function fieldTypes(): FieldTypeRegistry
    {
        return $this->fieldTypes;
    }

    /**
     * Reach the registry of entity types contributed by core and by extensions this process.
     *
     * @return  BusinessDefinitionContributionRegistry  The live registry; validating it as a graph is a
     *          separate, later step.
     *
     * @since   2.0.0
     */
    public function businessDefinitions(): BusinessDefinitionContributionRegistry
    {
        return $this->businessDefinitions;
    }

    /**
     * Check the contributed entity types as one graph, after every provider has contributed.
     *
     * Cross-package references only resolve once the last provider has run, so this cannot be folded
     * into registration and has to be driven by whoever owns the contribution phase as a whole.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function validateBusinessDefinitions(): void
    {
        $this->businessDefinitions->validate();
    }

    /**
     * The declared contribution kinds, in declaration order.
     *
     * Exists so a caller can walk every kind this set actually declares — the unit suite uses it to
     * prove each one is both inventoried and withdrawn on removal, without keeping a second list of
     * kinds in step with the first.
     *
     * @return  list<string>  Dotted inventory paths such as `capabilities` or `administrator.routes`.
     *
     * @since   2.0.0
     */
    public function surfaceKeys(): array
    {
        return array_keys($this->surfaces);
    }

    /**
     * Report everything one owner currently holds across every surface, for operator diagnostics.
     *
     * The dotted surface keys are expanded into nested groups, so `administrator.routes` is reported
     * as `administrator` → `routes`. An owner that contributed nothing still yields the full structure
     * with empty lists, which is what makes a missing contribution legible rather than invisible.
     *
     * @param   ContributionOwner  $owner  Contributor being inspected, usually one installed extension.
     *
     * @return  array<string, mixed>  Ungrouped surfaces first, then each group; every leaf is that
     *          surface's own export shape.
     *
     * @since   2.0.0
     */
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

    /**
     * Withdraw everything one owner contributed, across every surface.
     *
     * Surfaces are swept in reverse declaration order, so entity types go before the field types they
     * are built from, and the capabilities and workspaces everything else references are withdrawn
     * last. An owner with nothing registered is not an error, so disable, uninstall, and trust
     * revocation can all call this without first working out what the package actually contributed.
     *
     * @param   ContributionOwner  $owner  Contributor whose contributions are being withdrawn.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(ContributionOwner $owner): void
    {
        foreach (array_reverse($this->surfaces) as $surface) {
            $surface->remove($owner);
        }
    }
}
