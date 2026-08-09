<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\CMS\Portal\Contribution\PortalNavigationDefinition;
use Kumwe\CMS\Portal\Contribution\PortalRouteDefinition;
use Kumwe\CMS\Portal\Contribution\PortalRouteHandlerFactory;
use Kumwe\CMS\Portal\Contribution\PortalTemplateDefinition;
use Kumwe\CMS\Portal\Contribution\PortalWorkspaceDefinition;

/**
 * The single-owner, single-phase registrar an extension actually contributes through.
 *
 * It is the enforcement point between a manifest and the running shell. A manifest is signed and
 * inspectable before install; the code that runs afterwards is not, so nothing is taken on the
 * provider's word here. Every registration is rejected unless its identifier belongs to this owner
 * and has not already been used, and under strict mode it must additionally match, field for field,
 * a declaration the manifest made — with `complete()` catching the reverse omission. Closing at
 * `complete()` is what stops a provider that kept the registrar contributing later, once nothing is
 * left to reconcile against.
 *
 * Core and schema-1 packages are served by the same class in non-strict mode, where the declaration
 * set is empty and only the ownership and duplicate checks apply.
 *
 * @since  2.0.0
 */
final class OwnedExtensionContributionRegistrar implements ExtensionContributionRegistrar
{
    /**
     * Array exports of the manifest declarations, keyed by contribution kind and then by identifier.
     *
     * Comparison is on the exports rather than the objects, so a registration matches its declaration
     * only when every declared field is identical.
     *
     * @var    array<string, array<string, array<string, mixed>>>
     * @since  2.0.0
     */
    private array $expected;

    /**
     * Identifiers already registered in this phase, keyed by contribution kind.
     *
     * Scoped to this registrar alone, which is why a second registrar opened for the same owner starts
     * empty and its repeats surface as collisions inside the registries instead.
     *
     * @var    array<string, array<string, true>>
     * @since  2.0.0
     */
    private array $seen = [];

    /**
     * Whether the contribution phase has ended and further registration is refused.
     *
     * @var    bool
     * @since  2.0.0
     */
    private bool $closed = false;

    /**
     * Open a contribution phase for one owner against its manifest declarations.
     *
     * The declarations are indexed into comparable exports here, so the manifest set is read once
     * rather than searched on every registration.
     *
     * @param  ContributionOwner                 $owner       Contributor every registration is attributed to
     *         and namespace-checked against.
     * @param  ManifestContributionSet           $declared    What that owner's manifest said it would contribute.
     * @param  ExtensionContributionRegistrySet  $registries  Set the accepted contributions are written into.
     * @param  bool                              $strict      True reconciles against the declarations; false
     *         accepts undeclared and tolerates omissions.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly ContributionOwner $owner,
        ManifestContributionSet $declared,
        private readonly ExtensionContributionRegistrySet $registries,
        private readonly bool $strict,
    ) {
        $this->expected = [
            'capability' => $this->index($declared->capabilities()),
            'resource_policy' => $this->index($declared->resourcePolicies()),
            'workspace' => $this->index($declared->workspaces()),
            'navigation' => $this->index($declared->navigation()),
            'view' => $this->index($declared->views()),
            'route' => $this->index($declared->routes()),
            'portal_workspace' => $this->index($declared->portalWorkspaces()),
            'portal_navigation' => $this->index($declared->portalNavigation()),
            'portal_template' => $this->index($declared->portalTemplates()),
            'portal_route' => $this->index($declared->portalRoutes()),
            'field_type' => $this->businessIndex($declared->fieldTypes()),
            'business_definition' => $this->businessIndex($declared->businessDefinitions()),
        ];
    }

    /**
     * Reconcile a capability against the manifest and add it to the site-wide capability catalog.
     *
     * @param   CapabilityDefinition  $definition  Capability code with its operator-facing wording.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the code is outside the owner's namespace, repeated, undeclared
     *          or altered under strict mode, or already held by another owner.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function capability(CapabilityDefinition $definition): void
    {
        $this->accept('capability', $definition->id, $definition->toArray());
        $this->registries->capabilities()->register($this->owner, $definition);
    }

    /**
     * Reconcile a resource policy and activate it in the shared authorization registry.
     *
     * The bound capability must already have been registered by this owner, so contribution order is
     * part of the privilege boundary rather than a deferred reference that could resolve to someone else.
     *
     * @param   ResourcePolicyDefinition  $definition  Owner-bound action/resource declaration.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the policy is foreign, repeated, undeclared or altered,
     *          references a missing or foreign capability, grants a system identity from an extension,
     *          or collides with an existing binding.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function resourcePolicy(ResourcePolicyDefinition $definition): void
    {
        $this->accept('resource_policy', $definition->id, $definition->toArray());
        $this->registries->resourcePolicies()->register($this->owner, $definition);
    }

    /**
     * Reconcile a workspace against the manifest and add it to the administrator workspace registry.
     *
     * @param   AdministratorWorkspaceDefinition  $definition  Workspace identity, wording, and ordering priority.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is outside the owner's namespace, repeated,
     *          undeclared or altered under strict mode, or already registered.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function administratorWorkspace(AdministratorWorkspaceDefinition $definition): void
    {
        $this->accept('workspace', $definition->id, $definition->toArray());
        $this->registries->workspaces()->register($this->owner, $definition);
    }

    /**
     * Reconcile a navigation item against the manifest and add it to the administrator menu.
     *
     * The item's workspace and capability must already be registered by this same owner, so navigation
     * has to be contributed after them.
     *
     * @param   AdministratorNavigationDefinition  $definition  Link target, wording, and the workspace and
     *          capability it belongs to.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is outside the owner's namespace, repeated,
     *          undeclared or altered under strict mode, already registered, or
     *          naming a workspace or capability this owner has not registered.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function administratorNavigation(AdministratorNavigationDefinition $definition): void
    {
        $this->accept('navigation', $definition->id, $definition->toArray());
        $this->registries->navigation()->registerOwned($this->owner, $definition);
    }

    /**
     * Reconcile a view against the manifest and make its template renderable by contributed routes.
     *
     * The view is keyed by its name rather than an `id`, which is the identifier ownership is checked
     * against.
     *
     * @param   AdministratorViewDefinition  $definition  View name and the template it resolves to.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the name is outside the owner's namespace, repeated, undeclared
     *          or altered under strict mode, or already registered.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function administratorView(AdministratorViewDefinition $definition): void
    {
        $this->accept('view', $definition->name, $definition->toArray());
        $this->registries->views()->register($this->owner, $definition);
    }

    /**
     * Reconcile a route against the manifest and hold it, with its factory, until routes are mounted.
     *
     * Only the route declaration is reconciled; the factory is not part of the manifest and is kept as
     * given, to be called when the administrator routes are mounted into the application.
     *
     * @param   AdministratorRouteDefinition      $definition  Route name, path, methods, and the capability
     *          and view it references.
     * @param   AdministratorRouteHandlerFactory  $factory     Builds the route's handler at mount time.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the name is outside the owner's namespace, repeated, undeclared
     *          or altered under strict mode, references a capability or view this
     *          owner does not hold, or collides with an existing route.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function administratorRoute(
        AdministratorRouteDefinition $definition,
        AdministratorRouteHandlerFactory $factory,
    ): void {
        $this->accept('route', $definition->name, $definition->toArray());
        $this->registries->routes()->register($this->owner, $definition, $factory);
    }

    /**
     * Reconcile and publish an owner-bound portal workspace.
     *
     * @param   PortalWorkspaceDefinition  $definition  Manifest-declared workspace.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function portalWorkspace(PortalWorkspaceDefinition $definition): void
    {
        $this->accept('portal_workspace', $definition->id, $definition->toArray());
        $this->registries->portalWorkspaces()->register($this->owner, $definition);
    }

    /**
     * Reconcile and publish an owner-bound portal navigation item.
     *
     * @param   PortalNavigationDefinition  $definition  Manifest-declared navigation item.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function portalNavigation(PortalNavigationDefinition $definition): void
    {
        $this->accept('portal_navigation', $definition->id, $definition->toArray());
        $this->registries->portalNavigation()->register($this->owner, $definition);
    }

    /**
     * Reconcile and publish an owner-bound portal template.
     *
     * @param   PortalTemplateDefinition  $definition  Manifest-declared template.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function portalTemplate(PortalTemplateDefinition $definition): void
    {
        $this->accept('portal_template', $definition->name, $definition->toArray());
        $this->registries->portalTemplates()->register($this->owner, $definition);
    }

    /**
     * Reconcile and publish an owner-bound guarded portal route.
     *
     * @param   PortalRouteDefinition      $definition  Manifest-declared route.
     * @param   PortalRouteHandlerFactory  $factory     Handler factory retained until mount time.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function portalRoute(PortalRouteDefinition $definition, PortalRouteHandlerFactory $factory): void
    {
        $this->accept('portal_route', $definition->name, $definition->toArray());
        $this->registries->portalRoutes()->register($this->owner, $definition, $factory);
    }

    /**
     * Reconcile a field type against the manifest and publish it for business definitions to build on.
     *
     * The contribution owner is restated in the business context's own owner vocabulary before the
     * registry sees it, so the two contexts stay independent of each other's naming.
     *
     * @param   FieldTypeDefinition  $definition  Field-type structure, identified under the owner's namespace.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is outside the owner's namespace, repeated,
     *          or undeclared or altered under strict mode; the field-type registry
     *          raises its `InvalidBusinessDefinition` subclass for an identifier
     *          some owner already claimed.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function fieldType(FieldTypeDefinition $definition): void
    {
        $this->accept('field_type', $definition->id, $definition->toArray());
        $this->registries->fieldTypes()->register($this->businessOwner(), $definition);
    }

    /**
     * Reconcile an entity type against the manifest and add it to the contributed definition set.
     *
     * Only this definition is checked here. References between definitions are resolved later, when
     * the whole contributed set is validated as one graph.
     *
     * @param   EntityTypeDefinition  $definition  Entity type whose handle sits in the owner's namespace.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the handle is outside the owner's namespace, repeated, or
     *          undeclared or altered under strict mode; the definition registry
     *          raises its `InvalidBusinessDefinition` subclass when the handle is
     *          already registered or the definition names a different owner.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function businessDefinition(EntityTypeDefinition $definition): void
    {
        $this->accept('business_definition', $definition->handle, $definition->toArray());
        $this->registries->businessDefinitions()->register($this->businessOwner(), $definition);
    }

    /**
     * End the phase, having checked that nothing the manifest declared was left unregistered.
     *
     * This is the half of reconciliation the per-registration checks cannot do: an omission is only
     * visible once the provider says it is finished. A failed check leaves the registrar open, since
     * bootstrap is expected to abort rather than continue with a partially contributed extension.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  Under strict mode, when a declared contribution was never registered.
     * @throws  \LogicException  When the phase was already completed.
     *
     * @since   2.0.0
     */
    public function complete(): void
    {
        $this->assertOpen();
        if ($this->strict) {
            foreach ($this->expected as $kind => $items) {
                foreach (array_keys($items) as $identifier) {
                    if (!isset($this->seen[$kind][$identifier])) {
                        throw new InvalidArgumentException(sprintf(
                            'Provider omitted declared %s contribution %s.',
                            $kind,
                            $identifier,
                        ));
                    }
                }
            }
        }
        $this->closed = true;
    }

    /**
     * Apply every ownership, duplication, and manifest check one registration has to pass.
     *
     * Running before the registry write is what keeps a rejected contribution out of the shell
     * entirely, rather than leaving it registered and merely reported.
     *
     * @param   string                $kind        Contribution kind, as keyed in the declaration index.
     * @param   string                $identifier  Identifier this contribution claims.
     * @param   array<string, mixed>  $actual      Export of what is being registered, compared with the
     *          manifest declaration under strict mode.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is outside the owner's namespace, was already
     *          registered in this phase, or does not match its declaration.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    private function accept(string $kind, string $identifier, array $actual): void
    {
        $this->assertOpen();
        $this->owner->assertOwns($identifier, $kind);
        if (isset($this->seen[$kind][$identifier])) {
            throw new InvalidArgumentException(sprintf(
                'Provider registered %s %s more than once.',
                $kind,
                $identifier,
            ));
        }
        if ($this->strict && ($this->expected[$kind][$identifier] ?? null) !== $actual) {
            throw new InvalidArgumentException(sprintf(
                'Provider %s contribution %s does not match its manifest declaration.',
                $kind,
                $identifier,
            ));
        }
        $this->seen[$kind][$identifier] = true;
    }

    /**
     * Refuse any further use of a registrar whose contribution phase has ended.
     *
     * @return  void
     *
     * @throws  \LogicException  When `complete()` has already closed this registrar.
     *
     * @since   2.0.0
     */
    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \LogicException('The extension contribution phase is closed.');
        }
    }

    /**
     * Index declarations of one kind by identifier, keeping only their comparable exports.
     *
     * @param   iterable<ContributionDefinition>  $items  Declarations of a single kind from the manifest set.
     *
     * @return  array<string, array<string, mixed>>  Each declaration's export, keyed by its identifier.
     *
     * @since   2.0.0
     */
    private function index(iterable $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $result[$item->identifier()] = $item->toArray();
        }
        return $result;
    }

    /**
     * Index business declarations the same way, reading their identifier from the field each type uses.
     *
     * Business definitions predate the contribution vocabulary and expose `id` or `handle` rather than
     * an `identifier()` method, which is the only reason they cannot go through `index()`.
     *
     * @param   iterable<FieldTypeDefinition|EntityTypeDefinition>  $items  Declared field types or entity types.
     *
     * @return  array<string, array<string, mixed>>  Each declaration's export, keyed by its id or handle.
     *
     * @since   2.0.0
     */
    private function businessIndex(iterable $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $identifier = $item instanceof FieldTypeDefinition ? $item->id : $item->handle;
            $result[$identifier] = $item->toArray();
        }
        return $result;
    }

    /**
     * Restate this registrar's owner in the vocabulary the business context uses for ownership.
     *
     * @return  DefinitionOwner  The core definition owner for core, otherwise the matching extension owner.
     *
     * @since   2.0.0
     */
    private function businessOwner(): DefinitionOwner
    {
        return $this->owner->identifier() === ContributionOwner::CORE
            ? DefinitionOwner::core()
            : DefinitionOwner::extension($this->owner->identifier());
    }
}
