<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\App\Application\Automation\JobHandler;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\App\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\App\BusinessIntegration\Domain\DomainListenerDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\App\BusinessIntegration\Domain\JobContributionDefinition;
use Kumwe\App\BusinessIntegration\Domain\QueueContributionDefinition;
use Kumwe\App\BusinessIntegration\Domain\ScheduleContributionDefinition;
use Kumwe\App\BusinessIntegration\Domain\WebhookContributionDefinition;
use Kumwe\App\BusinessRecord\Application\MoneyRateProvider;
use Kumwe\App\BusinessRecord\Application\UnitConversionProvider;
use Kumwe\App\BusinessRecord\Domain\MoneyRateProviderDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\App\BusinessReporting\Application\ProjectionBuilder;
use Kumwe\App\BusinessReporting\Domain\ProjectionDefinition;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionContract;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionHandler;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessViewContract;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessViewHandler;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationContribution;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresenter;
use Kumwe\App\Extension\Runtime\RuntimeCanonicalJson;
use Kumwe\App\InterfaceStandard\SurfaceDefinition;
use Kumwe\App\Portal\Contribution\PortalNavigationDefinition;
use Kumwe\App\Portal\Contribution\PortalRouteDefinition;
use Kumwe\App\Portal\Contribution\PortalRouteHandlerFactory;
use Kumwe\App\Portal\Contribution\PortalTemplateDefinition;
use Kumwe\App\Portal\Contribution\PortalWorkspaceDefinition;

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
final class OwnedExtensionContributionRegistrar implements
    ExtensionContributionRegistrar,
    InterfaceSurfaceRegistrar,
    MoneyRateProviderRegistrar,
    ContentTranslationRegistrar,
    UnitConversionProviderRegistrar,
    CompositionContributionRegistrar
{
    /**
     * Array exports of the manifest declarations, keyed by contribution kind and then by identifier.
     *
     * Comparison is on canonical JSON exports rather than the objects, so a registration matches its
     * declaration only when every declared value and list position is identical; JSON object key order
     * cannot create false drift after runtime-publication canonicalisation.
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
            'interface_surface' => $this->index($declared->interfaceSurfaces()),
            'field_type' => $this->businessIndex($declared->fieldTypes()),
            'field_presentation' => $this->fieldPresentationIndex($declared->fieldPresentations()),
            'business_definition' => $this->businessIndex($declared->businessDefinitions()),
            'custom_business_view_handler' => $this->customIndex($declared->customBusinessViews()),
            'custom_business_action_handler' => $this->customIndex($declared->customBusinessActions()),
            'event_schema' => $this->index($declared->eventSchemas()),
            'domain_listener' => $this->index($declared->domainListeners()),
            'event_consumer' => $this->index($declared->eventConsumers()),
            'job' => $this->index($declared->jobs()),
            'queue' => $this->index($declared->queues()),
            'schedule' => $this->index($declared->schedules()),
            'projection' => $this->index($declared->projections()),
            'report' => $this->index($declared->reports()),
            'webhook' => $this->index($declared->webhooks()),
            'money_rate_provider' => $this->index($declared->moneyRateProviders()),
            'content_translation_group' => $this->index($declared->contentTranslationGroups()),
            'unit_conversion_provider' => $this->index($declared->unitConversionProviders()),
            'composition_block' => $this->index($declared->compositionBlocks()),
            'composition_pattern' => $this->index($declared->compositionPatterns()),
            'composition_field_control' => $this->index($declared->compositionFieldControls()),
            'composition_inspector' => $this->index($declared->compositionInspectors()),
            'composition_design_vocabulary' => $this->index($declared->compositionDesignVocabularies()),
            'composition_migration' => $this->index($declared->compositionMigrations()),
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
     * @since   2.0.0
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
     * @since   2.0.0
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
     * @since   2.0.0
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
     * @since   2.0.0
     */
    public function portalRoute(PortalRouteDefinition $definition, PortalRouteHandlerFactory $factory): void
    {
        $this->accept('portal_route', $definition->name, $definition->toArray());
        $this->registries->portalRoutes()->register($this->owner, $definition, $factory);
    }

    /**
     * Reconcile and publish one declarative KIS surface under this package owner.
     *
     * @param   SurfaceDefinition  $definition  Signed semantic declaration with no executable renderer.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When ownership, manifest equality, or uniqueness fails.
     * @throws  \LogicException  When the contribution phase has already closed.
     *
     * @since   2.0.0
     */
    public function interfaceSurface(SurfaceDefinition $definition): void
    {
        $this->accept('interface_surface', $definition->identifier(), $definition->toArray());
        $this->registries->interfaceSurfaces()->register($this->owner, $definition);
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
     * Reconcile and register one safe presenter for an already contributed field type.
     *
     * @param   FieldPresentationContribution  $contribution  Signed type and exact context coverage.
     * @param   FieldPresenter                 $presenter     Markup-free semantic presenter.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the declaration is foreign, absent, repeated, altered, or its
     *          field type has not already been contributed by this owner.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function fieldPresentation(
        FieldPresentationContribution $contribution,
        FieldPresenter $presenter,
    ): void {
        $this->assertAcceptable('field_presentation', $contribution->fieldType, $contribution->toArray());
        $ownedTypes = array_map(
            static fn (FieldTypeDefinition $definition): string => $definition->id,
            $this->registries->fieldTypes()->ownedBy($this->businessOwner()),
        );
        if (!in_array($contribution->fieldType, $ownedTypes, true)) {
            throw new InvalidArgumentException(
                'A field-presentation contribution must follow its owner\'s field-type contribution.',
            );
        }
        $this->registries->fieldPresentations()->register(
            $this->businessOwner(),
            $contribution->fieldType,
            $contribution->contexts,
            $presenter,
        );
        $this->recordAccepted('field_presentation', $contribution->fieldType);
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
     * Reconcile and register one typed custom business view handler.
     *
     * @param   CustomBusinessViewContract  $contract  Signed query and result contract.
     * @param   CustomBusinessViewHandler   $handler   Typed application handler implementation.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When ownership, manifest equality, handler uniqueness, or schema
     *          uniqueness fails.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function customBusinessViewHandler(
        CustomBusinessViewContract $contract,
        CustomBusinessViewHandler $handler,
    ): void {
        $this->accept('custom_business_view_handler', $contract->handler, $contract->toArray());
        $this->registries->customBusinessViewHandlers()->register($this->businessOwner(), $contract, $handler);
    }

    /**
     * Reconcile and register one typed custom business action handler.
     *
     * @param   CustomBusinessActionContract  $contract  Signed command and result contract.
     * @param   CustomBusinessActionHandler   $handler   Typed application handler implementation.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When ownership, manifest equality, handler uniqueness, or schema
     *          uniqueness fails.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function customBusinessActionHandler(
        CustomBusinessActionContract $contract,
        CustomBusinessActionHandler $handler,
    ): void {
        $this->accept('custom_business_action_handler', $contract->handler, $contract->toArray());
        $this->registries->customBusinessActionHandlers()->register($this->businessOwner(), $contract, $handler);
    }

    /**
     * Register the manifest-reconciled event schema under the current package owner.
     *
     * @param   EventSchemaDefinition  $definition  Signed contribution definition governing the operation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function eventSchema(EventSchemaDefinition $definition): void
    {
        $this->accept('event_schema', $definition->identifier(), $definition->toArray());
        $this->registries->eventSchemas()->register($this->owner, $definition);
    }

    /**
     * Register a domain listener only when its implementation matches the signed declaration.
     *
     * @param   DomainListenerDefinition  $definition  Signed contribution definition governing the operation.
     * @param   DomainEventHandler        $handler     Runtime handler bound to the signed contribution.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function domainListener(DomainListenerDefinition $definition, DomainEventHandler $handler): void
    {
        if ($handler->definition()->toArray() !== $definition->toArray()) {
            throw new InvalidArgumentException('A domain listener implementation contradicts its declaration.');
        }
        $this->accept('domain_listener', $definition->identifier(), $definition->toArray());
        $this->registries->domainListeners()->register($this->owner, $definition, $handler);
    }

    /**
     * Register a durable consumer only when its implementation matches the signed declaration.
     *
     * @param   EventConsumerDefinition  $definition  Signed contribution definition governing the operation.
     * @param   IntegrationEventHandler  $handler     Runtime handler bound to the signed contribution.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function eventConsumer(EventConsumerDefinition $definition, IntegrationEventHandler $handler): void
    {
        if ($handler->definition()->toArray() !== $definition->toArray()) {
            throw new InvalidArgumentException('An event consumer implementation contradicts its declaration.');
        }
        $this->accept('event_consumer', $definition->identifier(), $definition->toArray());
        $this->registries->eventConsumers()->register($this->owner, $definition, $handler);
    }

    /**
     * Register a job handler only when its type matches the signed declaration.
     *
     * @param   JobContributionDefinition  $definition  Signed contribution definition governing the operation.
     * @param   JobHandler                 $handler     Runtime handler bound to the signed contribution.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function jobHandler(JobContributionDefinition $definition, JobHandler $handler): void
    {
        if ($handler->type() !== $definition->identifier()) {
            throw new InvalidArgumentException('A job handler implementation contradicts its declaration.');
        }
        $this->accept('job', $definition->identifier(), $definition->toArray());
        $this->registries->jobs()->register($this->owner, $definition, $handler);
    }

    /**
     * Register a logical queue with bounded leases, retries, concurrency, and retention.
     *
     * @param   QueueContributionDefinition  $definition  Signed contribution definition governing the operation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function queue(QueueContributionDefinition $definition): void
    {
        $this->accept('queue', $definition->identifier(), $definition->toArray());
        $this->registries->queues()->register($this->owner, $definition);
    }

    /**
     * Register the manifest-reconciled schedule under the current package owner.
     *
     * @param   ScheduleContributionDefinition  $definition  Signed contribution definition governing the operation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function schedule(ScheduleContributionDefinition $definition): void
    {
        $this->accept('schedule', $definition->identifier(), $definition->toArray());
        $this->registries->schedules()->register($this->owner, $definition);
    }

    /**
     * Compile the report column projection for policy-safe record access.
     *
     * @param   ProjectionDefinition  $definition  Signed contribution definition governing the operation.
     * @param   ProjectionBuilder     $builder     Projection builder registered for the signed definition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function projection(ProjectionDefinition $definition, ProjectionBuilder $builder): void
    {
        if ($builder->definition()->toArray() !== $definition->toArray()) {
            throw new InvalidArgumentException('A projection builder contradicts its declaration.');
        }
        $this->accept('projection', $definition->identifier(), $definition->toArray());
        $this->registries->projections()->register($this->owner, $definition, $builder);
    }

    /**
     * Register the manifest-reconciled report under the current package owner.
     *
     * @param   ReportDefinition  $definition  Signed contribution definition governing the operation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function report(ReportDefinition $definition): void
    {
        $this->accept('report', $definition->identifier(), $definition->toArray());
        $this->registries->reports()->register($this->owner, $definition);
    }

    /**
     * Register an outbound adapter only when its implementation matches the signed declaration.
     *
     * @param   WebhookContributionDefinition  $definition  Signed contribution definition governing the operation.
     * @param   IntegrationEventTransport      $transport   Declared outbound transport bound to the webhook.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function webhook(
        WebhookContributionDefinition $definition,
        IntegrationEventTransport $transport,
    ): void {
        if (
            $transport->identifier() !== $definition->identifier()
            || $transport->sensitivityCeiling() !== $definition->sensitivityCeiling()
        ) {
            throw new InvalidArgumentException('An outbound adapter implementation contradicts its declaration.');
        }
        $this->accept('webhook', $definition->identifier(), $definition->toArray());
        $this->registries->webhooks()->register($this->owner, $definition, $transport);
    }

    /**
     * Register a rate provider only when its identity matches the signed declaration.
     *
     * Attribution is the point of the check. Every rate this implementation later supplies names a
     * provider, and a converted amount is only auditable if that name is the one the manifest published,
     * so an implementation answering under another identity is refused here rather than discovered in an
     * export months later.
     *
     * @param   MoneyRateProviderDefinition  $definition  Signed declaration naming the currencies it prices.
     * @param   MoneyRateProvider            $provider    Runtime implementation bound to that declaration.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the implementation answers under another identity, or the
     *          identifier is outside the owner's namespace, repeated, or undeclared or altered under strict mode.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function moneyRateProvider(
        MoneyRateProviderDefinition $definition,
        MoneyRateProvider $provider,
    ): void {
        if ($provider->identifier() !== $definition->identifier()) {
            throw new InvalidArgumentException('A money rate provider implementation contradicts its declaration.');
        }
        $this->accept('money_rate_provider', $definition->identifier(), $definition->toArray());
        $this->registries->moneyRateProviders()->register($this->owner, $definition, $provider);
    }

    /**
     * Register a multilingual content set only as the signed manifest declared it.
     *
     * The declaration is the closed claim, exactly as a rate provider's currency list is: an operator can
     * read which languages a package promises before installing it, and a package cannot widen that
     * promise afterwards by registering a locale set its manifest never carried. Contributed content is
     * content, so this is what makes a translation group reachable for an extension's items without a
     * core edit — the group, the per-locale publication state and the declared fallback are the same
     * model core content uses.
     *
     * @param   TranslationGroupDeclaration  $declaration  Signed declaration naming the content set, the
     *          locales it publishes and the locale it falls back to.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is outside the owner's namespace, repeated,
     *          or undeclared or altered under strict mode.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function contentTranslationGroup(TranslationGroupDeclaration $declaration): void
    {
        $this->accept('content_translation_group', $declaration->identifier(), $declaration->toArray());
        $this->registries->contentTranslationGroups()->register($this->owner, $declaration);
    }

    /**
     * Register a unit conversion provider only when its identity matches the signed declaration.
     *
     * Attribution is the point of the check. Every factor this implementation later supplies names a
     * provider, and a converted quantity is only auditable if that name is the one the manifest
     * published, so an implementation answering under another identity is refused here rather than
     * discovered in a stock count months later.
     *
     * @param   UnitConversionProviderDefinition  $definition  Signed declaration naming the units it relates.
     * @param   UnitConversionProvider            $provider    Runtime implementation bound to that declaration.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the implementation answers under another identity, or the
     *          identifier is outside the owner's namespace, repeated, or undeclared or altered under strict mode.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function unitConversionProvider(
        UnitConversionProviderDefinition $definition,
        UnitConversionProvider $provider,
    ): void {
        if ($provider->identifier() !== $definition->identifier()) {
            throw new InvalidArgumentException(
                'A unit conversion provider implementation contradicts its declaration.',
            );
        }
        $this->accept('unit_conversion_provider', $definition->identifier(), $definition->toArray());
        $this->registries->unitConversionProviders()->register($this->owner, $definition, $provider);
    }

    /**
     * Register a composition block only as the signed manifest declared it.
     *
     * The declaration is inert data — the Gate B surface is what will consume it — but the reconciliation
     * is not deferred with it: a provider cannot register a property schema, slot set or renderer binding
     * its manifest never carried, so what an operator inspected before install is what the surface will
     * eventually consume.
     *
     * @param   CompositionBlockDeclaration  $declaration  Signed declaration of one placeable block.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is outside the owner's namespace, repeated,
     *          or undeclared or altered under strict mode.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function compositionBlock(CompositionBlockDeclaration $declaration): void
    {
        $this->accept('composition_block', $declaration->identifier(), $declaration->toArray());
        $this->registries->compositionBlocks()->register($this->owner, $declaration);
    }

    /**
     * Register a composition pattern only as the signed manifest declared it.
     *
     * @param   CompositionPatternDeclaration  $declaration  Signed declaration of one reusable structure.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is outside the owner's namespace, repeated,
     *          or undeclared or altered under strict mode.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function compositionPattern(CompositionPatternDeclaration $declaration): void
    {
        $this->accept('composition_pattern', $declaration->identifier(), $declaration->toArray());
        $this->registries->compositionPatterns()->register($this->owner, $declaration);
    }

    /**
     * Register a composition field control only as the signed manifest declared it.
     *
     * @param   CompositionFieldControlDeclaration  $declaration  Signed declaration of one editing control.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is outside the owner's namespace, repeated,
     *          or undeclared or altered under strict mode.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function compositionFieldControl(CompositionFieldControlDeclaration $declaration): void
    {
        $this->accept('composition_field_control', $declaration->identifier(), $declaration->toArray());
        $this->registries->compositionFieldControls()->register($this->owner, $declaration);
    }

    /**
     * Register a composition inspector only as the signed manifest declared it.
     *
     * @param   CompositionInspectorDeclaration  $declaration  Signed declaration of one inspector panel.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is outside the owner's namespace, repeated,
     *          or undeclared or altered under strict mode.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function compositionInspector(CompositionInspectorDeclaration $declaration): void
    {
        $this->accept('composition_inspector', $declaration->identifier(), $declaration->toArray());
        $this->registries->compositionInspectors()->register($this->owner, $declaration);
    }

    /**
     * Register a design vocabulary only as the signed manifest declared it.
     *
     * @param   CompositionDesignVocabularyDeclaration  $declaration  Signed vocabulary a theme remaps.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is outside the owner's namespace, repeated,
     *          or undeclared or altered under strict mode.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function compositionDesignVocabulary(CompositionDesignVocabularyDeclaration $declaration): void
    {
        $this->accept('composition_design_vocabulary', $declaration->identifier(), $declaration->toArray());
        $this->registries->compositionDesignVocabularies()->register($this->owner, $declaration);
    }

    /**
     * Register a composition migration only as the signed manifest declared it.
     *
     * @param   CompositionMigrationDeclaration  $declaration  Signed declared document migration.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is outside the owner's namespace, repeated,
     *          or undeclared or altered under strict mode.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    public function compositionMigration(CompositionMigrationDeclaration $declaration): void
    {
        $this->accept('composition_migration', $declaration->identifier(), $declaration->toArray());
        $this->registries->compositionMigrations()->register($this->owner, $declaration);
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
        $this->assertAcceptable($kind, $identifier, $actual);
        $this->recordAccepted($kind, $identifier);
    }

    /**
     * Validate one registration without marking its declaration as fulfilled.
     *
     * Presenter registration uses this split form because its field-type prerequisite and registry write
     * must both succeed before `complete()` may treat the signed declaration as implemented.
     *
     * @param   string                $kind        Contribution kind, as keyed in the declaration index.
     * @param   string                $identifier  Identifier this contribution claims.
     * @param   array<string, mixed>  $actual      Export compared with the declaration under strict mode.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When ownership, uniqueness, or strict declaration equality fails.
     * @throws  \LogicException  When the contribution phase has already been completed.
     *
     * @since   2.0.0
     */
    private function assertAcceptable(string $kind, string $identifier, array $actual): void
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
        if (
            $this->strict
            && RuntimeCanonicalJson::encode($this->expected[$kind][$identifier] ?? null)
                !== RuntimeCanonicalJson::encode($actual)
        ) {
            throw new InvalidArgumentException(sprintf(
                'Provider %s contribution %s does not match its manifest declaration.',
                $kind,
                $identifier,
            ));
        }
    }

    /**
     * Mark one fully registered declaration as fulfilled for final reconciliation.
     *
     * @param   string  $kind        Contribution kind, as keyed in the declaration index.
     * @param   string  $identifier  Identifier whose registry write completed successfully.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function recordAccepted(string $kind, string $identifier): void
    {
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
     * Index signed custom handler contracts by their handler reference.
     *
     * @param   iterable<CustomBusinessViewContract|CustomBusinessActionContract>  $items  Contracts of one kind.
     *
     * @return  array<string, array<string, mixed>>  Contract exports keyed by handler reference.
     *
     * @since   2.0.0
     */
    private function customIndex(iterable $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $result[$item->handler] = $item->toArray();
        }
        return $result;
    }

    /**
     * Index signed field-presentation declarations by their exact field type.
     *
     * @param   iterable<FieldPresentationContribution>  $items  Presentation declarations for one owner.
     *
     * @return  array<string, array<string, mixed>>  Canonical exports keyed by field type.
     *
     * @since   2.0.0
     */
    private function fieldPresentationIndex(iterable $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $result[$item->fieldType] = $item->toArray();
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
