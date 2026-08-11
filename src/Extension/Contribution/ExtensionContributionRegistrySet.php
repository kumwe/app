<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use ArrayObject;
use Kumwe\CMS\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\CMS\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionContributionRegistry;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionValidator;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\CMS\Application\Automation\JobHandler;
use Kumwe\CMS\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\CMS\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\CMS\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\CMS\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\CMS\BusinessIntegration\Application\PayloadSchemaValidator;
use Kumwe\CMS\BusinessIntegration\Domain\DomainListenerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\WebhookContributionDefinition;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessActionHandlerRegistry;
use Kumwe\CMS\BusinessReporting\Application\ProjectionBuilder;
use Kumwe\CMS\BusinessReporting\Domain\ProjectionDefinition;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessReferenceRegistry;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessViewHandlerRegistry;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldPresentationRegistry;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Portal\Contribution\PortalNavigationRegistry;
use Kumwe\CMS\Portal\Contribution\PortalRouteRegistry;
use Kumwe\CMS\Portal\Contribution\PortalTemplateRegistry;
use Kumwe\CMS\Portal\Contribution\PortalWorkspaceRegistry;

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
     * Active owner identifiers keyed by their legacy dotted contribution namespace.
     *
     * The object wrapper permits lifecycle mutation inside this readonly composition root. Equal or
     * prefix-overlapping namespaces are refused before a provider can publish any definition, closing
     * the ambiguity created by replacing the package's slash with a dot.
     *
     * @var    ArrayObject<string, string>
     * @since  2.0.0
     */
    private ArrayObject $ownerNamespaces;

    /**
     * Canonical operational authorization registry populated by capability and policy contributions.
     *
     * @var    AuthorizationPolicyRegistry
     * @since  2.0.0
     */
    private AuthorizationPolicyRegistry $authorizationPolicies;

    /**
     * The site-wide permission vocabulary every guarded surface names a code from.
     *
     * @var    CapabilityDefinitionRegistry
     * @since  2.0.0
     */
    private CapabilityDefinitionRegistry $capabilities;

    /**
     * Owner-bound capability-to-resource policies enforced by the authorization gateway.
     *
     * @var    ResourcePolicyDefinitionRegistry
     * @since  2.0.0
     */
    private ResourcePolicyDefinitionRegistry $resourcePolicies;

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
     * Top-level ordinary-user portal workspaces.
     *
     * @var    PortalWorkspaceRegistry
     * @since  2.0.0
     */
    private PortalWorkspaceRegistry $portalWorkspaces;

    /**
     * Capability-filtered ordinary-user portal navigation.
     *
     * @var    PortalNavigationRegistry
     * @since  2.0.0
     */
    private PortalNavigationRegistry $portalNavigation;

    /**
     * Namespaced templates available to contributed portal routes.
     *
     * @var    PortalTemplateRegistry
     * @since  2.0.0
     */
    private PortalTemplateRegistry $portalTemplates;

    /**
     * Guarded ordinary-user portal extension routes.
     *
     * @var    PortalRouteRegistry
     * @since  2.0.0
     */
    private PortalRouteRegistry $portalRoutes;

    /**
     * Owner-bound registry of admitted KIS semantic interface declarations.
     *
     * @var    OwnedRuntimeContributionRegistry
     * @since  2.0.0
     */
    private OwnedRuntimeContributionRegistry $interfaceSurfaces;

    /**
     * The field types business definitions may build fields from, seeded by core rather than by itself.
     *
     * @var    FieldTypeRegistry
     * @since  2.0.0
     */
    private FieldTypeRegistry $fieldTypes;

    /**
     * Safe semantic field presenters contributed for exact field-type and context pairs.
     *
     * @var    FieldPresentationRegistry
     * @since  2.0.0
     */
    private FieldPresentationRegistry $fieldPresentations;

    /**
     * The entity types contributed this process, validated as one graph once every provider has run.
     *
     * @var    BusinessDefinitionContributionRegistry
     * @since  2.0.0
     */
    private BusinessDefinitionContributionRegistry $businessDefinitions;

    /**
     * Typed extension-specific view handlers with owner-bound signed schemas.
     *
     * @var    CustomBusinessViewHandlerRegistry
     * @since  2.0.0
     */
    private CustomBusinessViewHandlerRegistry $customBusinessViewHandlers;

    /**
     * Typed extension-specific action handlers with owner-bound signed schemas.
     *
     * @var    CustomBusinessActionHandlerRegistry
     * @since  2.0.0
     */
    private CustomBusinessActionHandlerRegistry $customBusinessActionHandlers;

    /**
     * Owner-bound runtime registry for event schemas.
     *
     * @var    OwnedRuntimeContributionRegistry  Versioned event schema declarations.
     * @since  2.0.0
     */
    private OwnedRuntimeContributionRegistry $eventSchemas;

    /**
     * Owner-bound runtime registry for domain listeners.
     *
     * @var    OwnedRuntimeContributionRegistry  Synchronous domain listeners.
     * @since  2.0.0
     */
    private OwnedRuntimeContributionRegistry $domainListeners;

    /**
     * Owner-bound runtime registry for event consumers.
     *
     * @var    OwnedRuntimeContributionRegistry  Durable integration consumers.
     * @since  2.0.0
     */
    private OwnedRuntimeContributionRegistry $eventConsumers;

    /**
     * Owner-bound runtime registry for jobs.
     *
     * @var    OwnedRuntimeContributionRegistry  Contributed job handlers.
     * @since  2.0.0
     */
    private OwnedRuntimeContributionRegistry $jobs;

    /**
     * Owner-bound runtime registry for queues.
     *
     * @var    OwnedRuntimeContributionRegistry  Logical queue declarations.
     * @since  2.0.0
     */
    private OwnedRuntimeContributionRegistry $queues;

    /**
     * Owner-bound runtime registry for schedules.
     *
     * @var    OwnedRuntimeContributionRegistry  Recurring schedule declarations.
     * @since  2.0.0
     */
    private OwnedRuntimeContributionRegistry $schedules;

    /**
     * Owner-bound runtime registry for projections.
     *
     * @var    OwnedRuntimeContributionRegistry  Rebuildable projection builders.
     * @since  2.0.0
     */
    private OwnedRuntimeContributionRegistry $projections;

    /**
     * Owner-bound runtime registry for reports.
     *
     * @var    OwnedRuntimeContributionRegistry  Safe report definitions.
     * @since  2.0.0
     */
    private OwnedRuntimeContributionRegistry $reports;

    /**
     * Owner-bound runtime registry for webhooks.
     *
     * @var    OwnedRuntimeContributionRegistry  Durable outbound adapters.
     * @since  2.0.0
     */
    private OwnedRuntimeContributionRegistry $webhooks;

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
     * @param  ?TrustStore                   $trust                  Source of live trust used to hide
     *         navigation whose owner is no longer trusted and active; null skips that filtering entirely.
     * @param  bool                          $withCore               Whether to register shipped core contributions.
     * @param  ?AuthorizationPolicyRegistry  $authorizationPolicies  Shared operational registry; a private
     *         empty registry is created for isolated sets.
     *
     * @since  2.0.0
     */
    public function __construct(
        ?TrustStore $trust = null,
        bool $withCore = true,
        ?AuthorizationPolicyRegistry $authorizationPolicies = null,
    ) {
        /** @var array<string, string> $ownerNamespaces */
        $ownerNamespaces = [];
        $this->ownerNamespaces = new ArrayObject($ownerNamespaces);
        $this->authorizationPolicies = $authorizationPolicies ?? new AuthorizationPolicyRegistry();
        $this->capabilities = new CapabilityDefinitionRegistry($this->authorizationPolicies);
        $this->resourcePolicies = new ResourcePolicyDefinitionRegistry($this->authorizationPolicies);
        $this->workspaces = new AdministratorWorkspaceRegistry();
        $this->navigation = new AdministratorNavigationRegistry(
            $this->workspaces,
            $this->capabilities,
            $trust,
        );
        $this->views = new AdministratorViewRegistry();
        $this->routes = new AdministratorRouteRegistry($this->capabilities, $this->views);
        $this->portalWorkspaces = new PortalWorkspaceRegistry();
        $this->portalNavigation = new PortalNavigationRegistry(
            $this->portalWorkspaces,
            $this->capabilities,
            $this->authorizationPolicies,
            $trust,
        );
        $this->portalTemplates = new PortalTemplateRegistry();
        $this->portalRoutes = new PortalRouteRegistry(
            $this->capabilities,
            $this->portalTemplates,
            $this->authorizationPolicies,
        );
        $this->interfaceSurfaces = new OwnedRuntimeContributionRegistry('interface surface');
        $this->fieldTypes = new FieldTypeRegistry(false);
        $this->fieldPresentations = new FieldPresentationRegistry();
        $this->businessDefinitions = new BusinessDefinitionContributionRegistry(
            new BusinessDefinitionValidator($this->fieldTypes),
        );
        $customBusinessReferences = new CustomBusinessReferenceRegistry();
        $this->customBusinessViewHandlers = new CustomBusinessViewHandlerRegistry($customBusinessReferences);
        $this->customBusinessActionHandlers = new CustomBusinessActionHandlerRegistry($customBusinessReferences);
        $this->eventSchemas = new OwnedRuntimeContributionRegistry('event schema');
        $this->domainListeners = new OwnedRuntimeContributionRegistry(
            'domain listener',
            DomainEventHandler::class,
        );
        $this->eventConsumers = new OwnedRuntimeContributionRegistry(
            'event consumer',
            IntegrationEventHandler::class,
        );
        $this->jobs = new OwnedRuntimeContributionRegistry('job', JobHandler::class);
        $this->queues = new OwnedRuntimeContributionRegistry('queue');
        $this->schedules = new OwnedRuntimeContributionRegistry('schedule');
        $this->projections = new OwnedRuntimeContributionRegistry('projection', ProjectionBuilder::class);
        $this->reports = new OwnedRuntimeContributionRegistry('report');
        $this->webhooks = new OwnedRuntimeContributionRegistry('webhook', IntegrationEventTransport::class);
        $this->surfaces = [
            'capabilities' => $this->capabilities,
            'resource_policies' => $this->resourcePolicies,
            'administrator.workspaces' => $this->workspaces,
            'administrator.navigation' => $this->navigation,
            'administrator.routes' => $this->routes,
            'administrator.views' => $this->views,
            'portal.workspaces' => $this->portalWorkspaces,
            'portal.navigation' => $this->portalNavigation,
            'portal.templates' => $this->portalTemplates,
            'portal.routes' => $this->portalRoutes,
            'interface.surfaces' => $this->interfaceSurfaces,
            'business.field_types' => BusinessContributionSurface::forFieldTypes($this->fieldTypes),
            'business.field_presentations' => BusinessContributionSurface::forFieldPresentations(
                $this->fieldPresentations,
            ),
            'business.definitions' => BusinessContributionSurface::forDefinitions($this->businessDefinitions),
            'business.view_handlers' => BusinessContributionSurface::forCustomViewHandlers(
                $this->customBusinessViewHandlers,
            ),
            'business.action_handlers' => BusinessContributionSurface::forCustomActionHandlers(
                $this->customBusinessActionHandlers,
            ),
            'integration.event_schemas' => $this->eventSchemas,
            'integration.domain_listeners' => $this->domainListeners,
            'integration.consumers' => $this->eventConsumers,
            'integration.jobs' => $this->jobs,
            'integration.queues' => $this->queues,
            'integration.schedules' => $this->schedules,
            'integration.projections' => $this->projections,
            'integration.reports' => $this->reports,
            'integration.webhooks' => $this->webhooks,
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
        $this->claimOwnerNamespace($owner);
        return new OwnedExtensionContributionRegistrar($owner, $declared, $this, $strict);
    }

    /**
     * Reserve one unambiguous namespace for the duration of its owner's active contribution lifecycle.
     *
     * Dot-bearing `vendor/name` identifiers can map to the same legacy dotted namespace, and one
     * namespace can otherwise sit beneath another owner's prefix. Either condition would make the
     * string-prefix ownership test ambiguous, so the second distinct owner fails before registration.
     * Reopening a phase for the same owner remains supported.
     *
     * @param   ContributionOwner  $owner  Owner whose provider is opening a contribution phase.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When another active owner has an equal or overlapping namespace.
     *
     * @since   2.0.0
     */
    private function claimOwnerNamespace(ContributionOwner $owner): void
    {
        $namespace = $owner->namespace();
        foreach ($this->ownerNamespaces as $claimedNamespace => $claimedOwner) {
            if ($claimedOwner === $owner->identifier()) {
                continue;
            }
            if (
                $namespace === $claimedNamespace
                || str_starts_with($namespace, $claimedNamespace . '.')
                || str_starts_with($claimedNamespace, $namespace . '.')
            ) {
                throw new \InvalidArgumentException(sprintf(
                    'Extension contribution namespace %s conflicts with active owner %s.',
                    $namespace,
                    $claimedOwner,
                ));
            }
        }
        $this->ownerNamespaces[$namespace] = $owner->identifier();
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
     * Reach the contribution surface for owner-bound resource policies.
     *
     * @return  ResourcePolicyDefinitionRegistry  Registry mirrored into the operational authorization catalog.
     *
     * @since   2.0.0
     */
    public function resourcePolicies(): ResourcePolicyDefinitionRegistry
    {
        return $this->resourcePolicies;
    }

    /**
     * Reach the canonical authorization registry populated through this contribution set.
     *
     * The composition root injects this same object into `DenyByDefaultAuthorizationGateway`, so
     * lifecycle removal immediately makes the owner's grants unenforceable without a parallel map.
     *
     * @return  AuthorizationPolicyRegistry  Live typed capability and resource-policy registry.
     *
     * @since   2.0.0
     */
    public function authorizationPolicies(): AuthorizationPolicyRegistry
    {
        return $this->authorizationPolicies;
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
     * Reach the portal workspace registry used by navigation grouping.
     *
     * @return  PortalWorkspaceRegistry  Live owner-aware portal workspaces.
     *
     * @since   2.0.0
     */
    public function portalWorkspaces(): PortalWorkspaceRegistry
    {
        return $this->portalWorkspaces;
    }

    /**
     * Reach the navigation registry rendered by the ordinary-user portal shell.
     *
     * @return  PortalNavigationRegistry  Live capability- and trust-filtered navigation.
     *
     * @since   2.0.0
     */
    public function portalNavigation(): PortalNavigationRegistry
    {
        return $this->portalNavigation;
    }

    /**
     * Reach the template registry used by contributed portal route handlers.
     *
     * @return  PortalTemplateRegistry  Live owner-aware portal templates.
     *
     * @since   2.0.0
     */
    public function portalTemplates(): PortalTemplateRegistry
    {
        return $this->portalTemplates;
    }

    /**
     * Reach the route registry mounted after the contribution phase completes.
     *
     * @return  PortalRouteRegistry  Live guarded contributed portal routes.
     *
     * @since   2.0.0
     */
    public function portalRoutes(): PortalRouteRegistry
    {
        return $this->portalRoutes;
    }

    /**
     * Return the active owner-bound KIS semantic surface declarations.
     *
     * @return  OwnedRuntimeContributionRegistry  Declarative surface registry with no executable payloads.
     *
     * @since   2.0.0
     */
    public function interfaceSurfaces(): OwnedRuntimeContributionRegistry
    {
        return $this->interfaceSurfaces;
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
     * Reach the owner-aware safe field-presentation registry populated by contribution providers.
     *
     * @return  FieldPresentationRegistry  Complete active type/context presentation registry.
     *
     * @since   2.0.0
     */
    public function fieldPresentations(): FieldPresentationRegistry
    {
        return $this->fieldPresentations;
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
     * Reach the owner-aware custom business view handler registry.
     *
     * @return  CustomBusinessViewHandlerRegistry  Registry validating query and result schemas at dispatch.
     *
     * @since   2.0.0
     */
    public function customBusinessViewHandlers(): CustomBusinessViewHandlerRegistry
    {
        return $this->customBusinessViewHandlers;
    }

    /**
     * Reach the owner-aware custom business action handler registry.
     *
     * @return  CustomBusinessActionHandlerRegistry  Registry enforcing command, result, and operation identity.
     *
     * @since   2.0.0
     */
    public function customBusinessActionHandlers(): CustomBusinessActionHandlerRegistry
    {
        return $this->customBusinessActionHandlers;
    }

    /**
     * Return the event schemas carried by this extension contribution registry set.
     *
     * @return  OwnedRuntimeContributionRegistry  Active event schemas.
     *
     * @since   2.0.0
     */
    public function eventSchemas(): OwnedRuntimeContributionRegistry
    {
        return $this->eventSchemas;
    }

    /**
     * Return the domain listeners carried by this extension contribution registry set.
     *
     * @return  OwnedRuntimeContributionRegistry  Active synchronous domain listeners.
     *
     * @since   2.0.0
     */
    public function domainListeners(): OwnedRuntimeContributionRegistry
    {
        return $this->domainListeners;
    }

    /**
     * Return the event consumers carried by this extension contribution registry set.
     *
     * @return  OwnedRuntimeContributionRegistry  Active durable event consumers.
     *
     * @since   2.0.0
     */
    public function eventConsumers(): OwnedRuntimeContributionRegistry
    {
        return $this->eventConsumers;
    }

    /**
     * Return the jobs carried by this extension contribution registry set.
     *
     * @return  OwnedRuntimeContributionRegistry  Active contributed job handlers.
     *
     * @since   2.0.0
     */
    public function jobs(): OwnedRuntimeContributionRegistry
    {
        return $this->jobs;
    }

    /**
     * Return the queues carried by this extension contribution registry set.
     *
     * @return  OwnedRuntimeContributionRegistry  Active logical queues.
     *
     * @since   2.0.0
     */
    public function queues(): OwnedRuntimeContributionRegistry
    {
        return $this->queues;
    }

    /**
     * Return the schedules carried by this extension contribution registry set.
     *
     * @return  OwnedRuntimeContributionRegistry  Active recurring schedule declarations.
     *
     * @since   2.0.0
     */
    public function schedules(): OwnedRuntimeContributionRegistry
    {
        return $this->schedules;
    }

    /**
     * Return the projections carried by this extension contribution registry set.
     *
     * @return  OwnedRuntimeContributionRegistry  Active rebuildable projection builders.
     *
     * @since   2.0.0
     */
    public function projections(): OwnedRuntimeContributionRegistry
    {
        return $this->projections;
    }

    /**
     * Return the reports carried by this extension contribution registry set.
     *
     * @return  OwnedRuntimeContributionRegistry  Active safe report definitions.
     *
     * @since   2.0.0
     */
    public function reports(): OwnedRuntimeContributionRegistry
    {
        return $this->reports;
    }

    /**
     * Return the webhooks carried by this extension contribution registry set.
     *
     * @return  OwnedRuntimeContributionRegistry  Active outbound adapters.
     *
     * @since   2.0.0
     */
    public function webhooks(): OwnedRuntimeContributionRegistry
    {
        return $this->webhooks;
    }

    /**
     * Check the contributed entity types as one graph, after every provider has contributed.
     *
     * Cross-package references only resolve once the last provider has run, so this cannot be folded
     * into registration and has to be driven by whoever owns the contribution phase as a whole. The same
     * pass proves each field type has active presenter coverage for every generated context it can reach.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the assembled graph or
     *          its presentation coverage is incomplete.
     *
     * @since   2.0.0
     */
    public function validateBusinessDefinitions(): void
    {
        $this->businessDefinitions->validate();
        foreach ($this->businessDefinitions->all() as $definition) {
            $this->fieldPresentations->assertCovers($definition);
        }
    }

    /**
     * Validate the complete cross-package event graph after every active provider has contributed.
     *
     * A package may consume a public event owned by core or another package, so manifest-local parsing
     * cannot resolve those references. This pass runs before extension boot and refuses an unavailable
     * schema revision, a sensitivity mismatch, or a listener/projection/adapter with no active source.
     *
     * @return  EventContractRegistry  Immutable catalog safe to share with dispatchers and workers.
     *
     * @throws  \InvalidArgumentException  When an integration contribution graph is inconsistent.
     * @throws  \LogicException  When an internal registry contains the wrong definition type.
     *
     * @since   2.0.0
     */
    public function validateIntegrationContributions(): EventContractRegistry
    {
        $schemas = $this->definitionsOf($this->eventSchemas, EventSchemaDefinition::class);
        $consumers = $this->definitionsOf($this->eventConsumers, EventConsumerDefinition::class);
        $catalog = new EventContractRegistry($schemas, $consumers, new PayloadSchemaValidator());

        foreach ($this->definitionsOf($this->domainListeners, DomainListenerDefinition::class) as $listener) {
            foreach ($listener->schemaVersions() as $version) {
                $catalog->schema($listener->eventType(), $version);
            }
        }
        foreach ($this->definitionsOf($this->projections, ProjectionDefinition::class) as $projection) {
            foreach ($projection->sources as $source) {
                foreach ($source->schemaVersions as $version) {
                    $schema = $catalog->schema($source->eventType, $version);
                    if (!$schema->sensitivity()->allowedBy($projection->sensitivityCeiling)) {
                        throw new \InvalidArgumentException(
                            'A contributed projection sensitivity ceiling is too low.',
                        );
                    }
                }
            }
        }
        foreach ($this->definitionsOf($this->webhooks, WebhookContributionDefinition::class) as $webhook) {
            foreach ($webhook->eventTypes() as $eventType) {
                foreach ($webhook->schemaVersions() as $version) {
                    $schema = $catalog->schema($eventType, $version);
                    if (!$schema->sensitivity()->allowedBy($webhook->sensitivityCeiling())) {
                        throw new \InvalidArgumentException(
                            'A contributed webhook sensitivity ceiling is too low.',
                        );
                    }
                }
            }
        }

        return $catalog;
    }

    /**
     * Read one generic runtime surface as an exact definition type.
     *
     * @template T of ContributionDefinition
     *
     * @param   OwnedRuntimeContributionRegistry  $registry  Generic owner-aware surface.
     * @param   class-string<T>                   $class     Required declaration type.
     *
     * @return  list<T>  Definitions in stable identifier order.
     *
     * @throws  \LogicException  When composition put another definition type in the surface.
     *
     * @since   2.0.0
     */
    private function definitionsOf(OwnedRuntimeContributionRegistry $registry, string $class): array
    {
        $result = [];
        foreach ($registry->definitions() as $definition) {
            if (!$definition instanceof $class) {
                throw new \LogicException('An integration contribution registry contains an invalid definition.');
            }
            $result[] = $definition;
        }

        return $result;
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
        $namespace = $owner->namespace();
        if (($this->ownerNamespaces[$namespace] ?? null) === $owner->identifier()) {
            unset($this->ownerNamespaces[$namespace]);
        }
    }
}
