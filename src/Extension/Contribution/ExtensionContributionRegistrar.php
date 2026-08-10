<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\CMS\Application\Automation\JobHandler;
use Kumwe\CMS\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\CMS\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\CMS\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\CMS\BusinessIntegration\Domain\DomainListenerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\JobContributionDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\QueueContributionDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\ScheduleContributionDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\WebhookContributionDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportDefinition;
use Kumwe\CMS\BusinessReporting\Application\ProjectionBuilder;
use Kumwe\CMS\BusinessReporting\Domain\ProjectionDefinition;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessActionContract;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessActionHandler;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessViewContract;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessViewHandler;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldPresentationContribution;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldPresenter;
use Kumwe\CMS\Portal\Contribution\PortalNavigationDefinition;
use Kumwe\CMS\Portal\Contribution\PortalRouteDefinition;
use Kumwe\CMS\Portal\Contribution\PortalRouteHandlerFactory;
use Kumwe\CMS\Portal\Contribution\PortalTemplateDefinition;
use Kumwe\CMS\Portal\Contribution\PortalWorkspaceDefinition;

/**
 * The sink a contribution provider registers each of its declared surfaces through.
 *
 * This is the whole of an extension's reach into the CMS shell: it never sees the registries behind
 * the methods, so it cannot read, replace, or withdraw anything another package contributed. Every
 * implementation is bound to one owner for one contribution phase, rejects an identifier outside that
 * owner's namespace, rejects the same identifier twice, and stops accepting once the phase closes.
 *
 * Order within a phase matters: resource policies name capabilities, navigation items name workspaces
 * and capabilities, and routes name capabilities and views. Every referenced contribution must already
 * have been registered by this same owner.
 *
 * @since  2.0.0
 */
interface ExtensionContributionRegistrar
{
    /**
     * Add one permission code to the site-wide capability vocabulary.
     *
     * A contributed capability is catalogued but granted to nobody, so an operator still has to assign
     * it to a role before the surfaces guarded by it become reachable.
     *
     * @param   CapabilityDefinition  $definition  Capability code with the wording shown to an operator.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function capability(CapabilityDefinition $definition): void;

    /**
     * Bind one owned capability to the bounded resource selectors it may authorize.
     *
     * The capability must already have been registered by this owner. Extensions cannot attach a
     * policy to someone else's capability or grant authority to a core system identity.
     *
     * @param   ResourcePolicyDefinition  $definition  Owner-namespaced typed action/resource binding.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function resourcePolicy(ResourcePolicyDefinition $definition): void;

    /**
     * Add a top-level grouping that administrator navigation items can be filed under.
     *
     * @param   AdministratorWorkspaceDefinition  $definition  Workspace identity, wording, and ordering priority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function administratorWorkspace(AdministratorWorkspaceDefinition $definition): void;

    /**
     * Add one entry to the administrator navigation.
     *
     * The entry is presented only to an operator holding its capability, and only while its owner is
     * still trusted, so contributing it is not by itself enough to make the page visible.
     *
     * @param   AdministratorNavigationDefinition  $definition  Link target, wording, and the workspace and
     *          capability it belongs to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function administratorNavigation(AdministratorNavigationDefinition $definition): void;

    /**
     * Add a named template that contributed administrator routes may render.
     *
     * @param   AdministratorViewDefinition  $definition  View name and the template it resolves to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function administratorView(AdministratorViewDefinition $definition): void;

    /**
     * Add one guarded administrator route and the factory that builds its handler.
     *
     * The handler is built later, when the application is routed, and is wrapped in authorization and
     * live trust enforcement, so the factory runs at wiring time rather than on the request path.
     *
     * @param   AdministratorRouteDefinition      $definition  Route name, path, methods, and the capability
     *          and view it references.
     * @param   AdministratorRouteHandlerFactory  $factory     Builds the route's handler once the renderer exists.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function administratorRoute(
        AdministratorRouteDefinition $definition,
        AdministratorRouteHandlerFactory $factory,
    ): void;

    /**
     * Add a top-level workspace to the ordinary-user portal shell.
     *
     * @param   PortalWorkspaceDefinition  $definition  Owner-bound workspace declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function portalWorkspace(PortalWorkspaceDefinition $definition): void;

    /**
     * Add one capability-filtered item to portal navigation.
     *
     * @param   PortalNavigationDefinition  $definition  Owner-bound navigation declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function portalNavigation(PortalNavigationDefinition $definition): void;

    /**
     * Add a namespaced template that a contributed portal route may render.
     *
     * @param   PortalTemplateDefinition  $definition  Owner-bound portal template declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function portalTemplate(PortalTemplateDefinition $definition): void;

    /**
     * Add one guarded portal route and the factory that builds its handler.
     *
     * @param   PortalRouteDefinition      $definition  Route and authorization declaration.
     * @param   PortalRouteHandlerFactory  $factory     Handler factory invoked at route-mount time.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function portalRoute(PortalRouteDefinition $definition, PortalRouteHandlerFactory $factory): void;

    /**
     * Add a field type that business definitions may build fields from.
     *
     * @param   FieldTypeDefinition  $definition  Field-type structure, identified under the owner's namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function fieldType(FieldTypeDefinition $definition): void;

    /**
     * Add one safe semantic presenter for an owned field type and its declared contexts.
     *
     * The executable presenter is reconciled against the signed declaration and receives only a typed,
     * already policy-filtered `FieldPresentationRequest`; it cannot emit markup or access delivery state.
     *
     * @param   FieldPresentationContribution  $contribution  Signed field type and context coverage.
     * @param   FieldPresenter                 $presenter     Transport-free semantic presenter implementation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function fieldPresentation(
        FieldPresentationContribution $contribution,
        FieldPresenter $presenter,
    ): void;

    /**
     * Add an entity type this package owns to the contributed business-definition set.
     *
     * The whole contributed set is validated as one graph after every provider has run, so a
     * definition referencing another package's type fails the phase rather than this call.
     *
     * @param   EntityTypeDefinition  $definition  Entity type whose handle and owner sit in the owner's namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function businessDefinition(EntityTypeDefinition $definition): void;

    /**
     * Add one typed extension-specific business view handler and its signed schema contract.
     *
     * @param   CustomBusinessViewContract  $contract  Manifest-equivalent query and result schemas.
     * @param   CustomBusinessViewHandler   $handler   Delivery-neutral application handler implementation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function customBusinessViewHandler(
        CustomBusinessViewContract $contract,
        CustomBusinessViewHandler $handler,
    ): void;

    /**
     * Add one typed extension-specific business action handler and its signed schema contract.
     *
     * @param   CustomBusinessActionContract  $contract  Manifest-equivalent command and result schemas.
     * @param   CustomBusinessActionHandler   $handler   Delivery-neutral application handler implementation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function customBusinessActionHandler(
        CustomBusinessActionContract $contract,
        CustomBusinessActionHandler $handler,
    ): void;

    /**
     * Publish one immutable event schema revision.
     *
     * @param   EventSchemaDefinition  $definition  Versioned payload and sensitivity contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function eventSchema(EventSchemaDefinition $definition): void;

    /**
     * Register one transaction-local domain listener against its exact signed declaration.
     *
     * @param   DomainListenerDefinition  $definition  Listener event, versions, priority, and revision.
     * @param   DomainEventHandler        $handler     Synchronous implementation; throwing aborts the mutation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function domainListener(DomainListenerDefinition $definition, DomainEventHandler $handler): void;

    /**
     * Register one durable, inbox-deduplicated integration-event consumer.
     *
     * @param   EventConsumerDefinition  $definition  Version, ordering, queue, retry, and sensitivity contract.
     * @param   IntegrationEventHandler  $handler     Idempotent consumer implementation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function eventConsumer(EventConsumerDefinition $definition, IntegrationEventHandler $handler): void;

    /**
     * Register an extension job handler with its payload schema and retry declaration.
     *
     * @param   JobContributionDefinition  $definition  Job type, payload, queue, and executable revision.
     * @param   JobHandler                 $handler     Existing durable-worker handler contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function jobHandler(JobContributionDefinition $definition, JobHandler $handler): void;

    /**
     * Register a logical queue with bounded leases, retries, concurrency, and retention.
     *
     * @param   QueueContributionDefinition  $definition  Logical queue limits.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function queue(QueueContributionDefinition $definition): void;

    /**
     * Register a recurring schedule against its declared job and queue.
     *
     * @param   ScheduleContributionDefinition  $definition  Recurring owned job.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function schedule(ScheduleContributionDefinition $definition): void;

    /**
     * Register a disposable projection builder against its signed rebuild contract.
     *
     * @param   ProjectionDefinition  $definition  Projection event and rebuild contract.
     * @param   ProjectionBuilder     $builder     Idempotent derived-state builder.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function projection(ProjectionDefinition $definition, ProjectionBuilder $builder): void;

    /**
     * Register a policy-aware report definition for delivery surfaces.
     *
     * @param   ReportDefinition  $definition  Safe permission-aware report.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function report(ReportDefinition $definition): void;

    /**
     * Register a durable outbound adapter without exposing its destination or credentials in inventory.
     *
     * @param   WebhookContributionDefinition  $definition  Event, queue, retry, and disclosure contract.
     * @param   IntegrationEventTransport      $transport   Trusted outbound implementation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function webhook(
        WebhookContributionDefinition $definition,
        IntegrationEventTransport $transport,
    ): void;
}
