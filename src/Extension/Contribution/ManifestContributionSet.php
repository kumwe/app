<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDefinitionLifecycle;
use Kumwe\App\Application\Authorization\ResourcePolicyTarget;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\App\BusinessIntegration\Application\PayloadSchemaValidator;
use Kumwe\App\BusinessIntegration\Domain\DomainListenerDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\App\BusinessIntegration\Domain\JobContributionDefinition;
use Kumwe\App\BusinessIntegration\Domain\QueueContributionDefinition;
use Kumwe\App\BusinessIntegration\Domain\ScheduleContributionDefinition;
use Kumwe\App\BusinessIntegration\Domain\WebhookContributionDefinition;
use Kumwe\App\BusinessRecord\Domain\MoneyRateProviderDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\App\BusinessReporting\Domain\ProjectionDefinition;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionContract;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessViewContract;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationContribution;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationCoverage;
use Kumwe\App\Extension\Domain\ExtensionIdentifier;
use Kumwe\App\Extension\Domain\Internal\ExtensionManifestGrammar;
use Kumwe\App\Studio\Domain\Contract\SchemaProfileRejected;
use Kumwe\App\Studio\Domain\Contract\SchemaPropertyProfile;
use Kumwe\App\Studio\Domain\Contract\StudioContractSchemas;
use Kumwe\App\Identity\Domain\Capability;
use Kumwe\App\InterfaceStandard\SurfaceArea;
use Kumwe\App\InterfaceStandard\SurfaceDefinition;
use Kumwe\App\Portal\Contribution\PortalNavigationDefinition;
use Kumwe\App\Portal\Contribution\PortalRouteDefinition;
use Kumwe\App\Portal\Contribution\PortalTemplateDefinition;
use Kumwe\App\Portal\Contribution\PortalWorkspaceDefinition;
use stdClass;

/**
 * The contributions one package declares, parsed, ordered, and checked for internal consistency.
 *
 * This is the declaration half of the extension contribution contract. A manifest is signed and can be
 * inspected before any of the package's code runs, so what it promises here is what the runtime later
 * holds the provider to — the set is built once and then only compared against. Construction accepts
 * nothing that would already be incoherent: identifiers must sit in the owner's namespace, none may be
 * declared twice, and navigation and routes may only reference a workspace, capability, or view
 * declared in this same set.
 *
 * Entries are indexed and sorted by identifier, so two manifests listing the same contributions in a
 * different order produce the same exports and the same reconciliation outcome.
 *
 * @since  2.0.0
 */
final readonly class ManifestContributionSet
{
    /**
     * Version of the contribution service-provider interface this class reads and writes.
     *
     * Bumping it is how a future incompatible manifest shape is separated from this one; a manifest
     * declaring any other version is refused rather than interpreted.
     *
     * @var    int
     * @since  2.0.0
     */
    public const SPI_VERSION = 1;

    /**
     * Current contribution SPI used by manifest schema 4 business-integration packages.
     *
     * Keeping `SPI_VERSION` at one preserves the public constant and exact schema-2/3 export bytes.
     *
     * @var    int
     * @since  2.0.0
     */
    public const CURRENT_SPI_VERSION = 2;

    /**
     * Contribution SPI used by manifest schema 5 packages, which opened the composition surfaces.
     *
     * The constant sits beside `CURRENT_SPI_VERSION` rather than replacing its value, because schema-4
     * manifests are frozen on SPI 2: changing what schema 4 requires would refuse every admitted
     * schema-4 package. A schema-5 manifest must declare `contributions.version` as 3, and only schema 5
     * may carry the `composition` contribution section.
     *
     * @var    int
     * @since  2.0.0
     */
    public const COMPOSITION_SPI_VERSION = 3;

    /**
     * Contribution SPI used by manifest schema 6 packages, which carry canonical Studio documents.
     *
     * Schema 6 replaces the frozen schema-5 paraphrase vocabulary with the exact canonical Studio
     * contribution documents and their separate bounded host bindings (kumwe/app#104, decision
     * D16). The constant sits beside the earlier versions rather than replacing any of them: a
     * schema-6 manifest must declare `contributions.version` as 4, an earlier schema refuses SPI 4,
     * and schema 5 remains frozen on SPI 3 byte for byte.
     *
     * @var    int
     * @since  2.0.0
     */
    public const CANONICAL_COMPOSITION_SPI_VERSION = 4;

    /**
     * Declared permission codes, keyed and sorted by capability identifier.
     *
     * @var    array<string, CapabilityDefinition>
     * @since  2.0.0
     */
    private array $capabilities;

    /**
     * Declared capability-to-resource bindings, keyed and sorted by policy identifier.
     *
     * @var    array<string, ResourcePolicyDefinition>
     * @since  2.0.0
     */
    private array $resourcePolicies;

    /**
     * Declared administrator workspaces, keyed and sorted by workspace identifier.
     *
     * @var    array<string, AdministratorWorkspaceDefinition>
     * @since  2.0.0
     */
    private array $workspaces;

    /**
     * Declared administrator navigation entries, keyed and sorted by item identifier.
     *
     * @var    array<string, AdministratorNavigationDefinition>
     * @since  2.0.0
     */
    private array $navigation;

    /**
     * Declared administrator routes, keyed and sorted by route name.
     *
     * @var    array<string, AdministratorRouteDefinition>
     * @since  2.0.0
     */
    private array $routes;

    /**
     * Declared administrator views, keyed and sorted by view name.
     *
     * @var    array<string, AdministratorViewDefinition>
     * @since  2.0.0
     */
    private array $views;

    /**
     * Declared portal workspaces keyed and sorted by workspace identifier.
     *
     * @var    array<string, PortalWorkspaceDefinition>
     * @since  2.0.0
     */
    private array $portalWorkspaces;

    /**
     * Declared portal navigation entries keyed and sorted by item identifier.
     *
     * @var    array<string, PortalNavigationDefinition>
     * @since  2.0.0
     */
    private array $portalNavigation;

    /**
     * Declared portal routes keyed and sorted by route name.
     *
     * @var    array<string, PortalRouteDefinition>
     * @since  2.0.0
     */
    private array $portalRoutes;

    /**
     * Declared portal templates keyed and sorted by template name.
     *
     * @var    array<string, PortalTemplateDefinition>
     * @since  2.0.0
     */
    private array $portalTemplates;

    /**
     * Conformant semantic interface surfaces, keyed and sorted by stable surface identifier.
     *
     * @var    array<string, SurfaceDefinition>
     * @since  2.0.0
     */
    private array $interfaceSurfaces;

    /**
     * Declared field types, keyed and sorted by field-type identifier.
     *
     * @var    array<string, FieldTypeDefinition>
     * @since  2.0.0
     */
    private array $fieldTypes;

    /**
     * Declared safe field presenters, keyed and sorted by their owned field-type identifier.
     *
     * @var    array<string, FieldPresentationContribution>
     * @since  2.0.0
     */
    private array $fieldPresentations;

    /**
     * Declared entity types, keyed and sorted by definition handle.
     *
     * @var    array<string, EntityTypeDefinition>
     * @since  2.0.0
     */
    private array $businessDefinitions;

    /**
     * Declared custom business view handlers and schema contracts, keyed by handler reference.
     *
     * @var    array<string, CustomBusinessViewContract>
     * @since  2.0.0
     */
    private array $customBusinessViews;

    /**
     * Declared custom business action handlers and schema contracts, keyed by handler reference.
     *
     * @var    array<string, CustomBusinessActionContract>
     * @since  2.0.0
     */
    private array $customBusinessActions;

    /**
     * Manifest-declared event schemas keyed by stable identifier.
     *
     * @var    array<string, EventSchemaDefinition>  Declared event schemas.
     * @since  2.0.0
     */
    private array $eventSchemas;

    /**
     * Manifest-declared domain listeners keyed by stable identifier.
     *
     * @var    array<string, DomainListenerDefinition>  Declared synchronous listeners.
     * @since  2.0.0
     */
    private array $domainListeners;

    /**
     * Manifest-declared event consumers keyed by stable identifier.
     *
     * @var    array<string, EventConsumerDefinition>  Declared durable consumers.
     * @since  2.0.0
     */
    private array $eventConsumers;

    /**
     * Manifest-declared jobs keyed by stable identifier.
     *
     * @var    array<string, JobContributionDefinition>  Declared job handlers and payload schemas.
     * @since  2.0.0
     */
    private array $jobs;

    /**
     * Manifest-declared queues keyed by stable identifier.
     *
     * @var    array<string, QueueContributionDefinition>  Declared logical queues.
     * @since  2.0.0
     */
    private array $queues;

    /**
     * Manifest-declared schedules keyed by stable identifier.
     *
     * @var    array<string, ScheduleContributionDefinition>  Declared recurring schedules.
     * @since  2.0.0
     */
    private array $schedules;

    /**
     * Manifest-declared projections keyed by stable identifier.
     *
     * @var    array<string, ProjectionDefinition>  Declared rebuildable projections.
     * @since  2.0.0
     */
    private array $projections;

    /**
     * Manifest-declared reports keyed by stable identifier.
     *
     * @var    array<string, ReportDefinition>  Declared safe reports.
     * @since  2.0.0
     */
    private array $reports;

    /**
     * Manifest-declared webhooks keyed by stable identifier.
     *
     * @var    array<string, WebhookContributionDefinition>  Declared outbound adapters.
     * @since  2.0.0
     */
    private array $webhooks;

    /**
     * Manifest-declared money rate providers keyed by stable identifier.
     *
     * @var    array<string, MoneyRateProviderDefinition>  Declared sources of exchange rates.
     * @since  2.0.0
     */
    private array $moneyRateProviders;

    /**
     * Manifest-declared unit conversion providers keyed by stable identifier.
     *
     * @var    array<string, UnitConversionProviderDefinition>  Declared sources of unit conversion factors.
     * @since  2.0.0
     */
    private array $unitConversionProviders;

    /**
     * Manifest-declared content translation groups keyed by stable identifier.
     *
     * @var    array<string, TranslationGroupDeclaration>  Declared multilingual content sets.
     * @since  2.0.0
     */
    private array $contentTranslationGroups;

    /**
     * Manifest-declared composition blocks keyed by stable identifier.
     *
     * @var    array<string, CompositionBlockDeclaration>  Declared placeable blocks.
     * @since  2.0.0
     */
    private array $compositionBlocks;

    /**
     * Manifest-declared composition patterns keyed by stable identifier.
     *
     * @var    array<string, CompositionPatternDeclaration>  Declared reusable structures.
     * @since  2.0.0
     */
    private array $compositionPatterns;

    /**
     * Manifest-declared composition field controls keyed by stable identifier.
     *
     * @var    array<string, CompositionFieldControlDeclaration>  Declared editing controls.
     * @since  2.0.0
     */
    private array $compositionFieldControls;

    /**
     * Manifest-declared composition inspectors keyed by stable identifier.
     *
     * @var    array<string, CompositionInspectorDeclaration>  Declared inspector panels.
     * @since  2.0.0
     */
    private array $compositionInspectors;

    /**
     * Manifest-declared composition design vocabularies keyed by stable identifier.
     *
     * @var    array<string, CompositionDesignVocabularyDeclaration>  Declared tokens, recipes and size roles.
     * @since  2.0.0
     */
    private array $compositionDesignVocabularies;

    /**
     * Manifest-declared composition migrations keyed by stable identifier.
     *
     * @var    array<string, CompositionMigrationDeclaration>  Declared document migrations.
     * @since  2.0.0
     */
    private array $compositionMigrations;

    /**
     * Manifest-declared canonical Studio composition documents keyed by kind-scoped identity.
     *
     * @var    array<string, CanonicalCompositionDocument>  Declared canonical documents.
     * @since  2.0.0
     */
    private array $canonicalCompositionDocuments;

    /**
     * Manifest-declared host bindings keyed by their document's kind-scoped identity.
     *
     * @var    array<string, CompositionHostBinding>  Declared bounded host metadata.
     * @since  2.0.0
     */
    private array $compositionHostBindings;

    /**
     * Assemble one package's declarations and reject any set that is already inconsistent.
     *
     * Called directly only for an empty or hand-built set, such as core's; a real manifest arrives
     * through `fromManifest()`. Business identifiers are checked against the business context's own
     * owner, which is why a field type or entity type belonging to another package fails here.
     *
     * @param ContributionOwner $owner Package declaring all of it.
     * @param   iterable<CapabilityDefinition>                    $capabilities              Permission codes it adds.
     * @param   iterable<AdministratorWorkspaceDefinition>        $workspaces                Administrator groupings.
     * @param   iterable<AdministratorNavigationDefinition>       $navigation                Menu entries it adds.
     * @param   iterable<AdministratorRouteDefinition>            $routes                    Guarded routes it serves.
     * @param iterable<AdministratorViewDefinition> $views Templates its routes render.
     * @param   iterable<FieldTypeDefinition>                     $fieldTypes                Field types it publishes.
     * @param   iterable<EntityTypeDefinition>                    $businessDefinitions       Entity types it publishes.
     * @param iterable<ResourcePolicyDefinition> $resourcePolicies Capability/resource bindings.
     * @param   iterable<PortalWorkspaceDefinition>               $portalWorkspaces          Portal groupings it adds.
     * @param iterable<PortalNavigationDefinition> $portalNavigation Portal menu entries it adds.
     * @param iterable<PortalRouteDefinition> $portalRoutes Guarded portal routes it serves.
     * @param iterable<PortalTemplateDefinition> $portalTemplates Portal templates its routes render.
     * @param iterable<CustomBusinessViewContract> $customBusinessViews Custom view handler contracts.
     * @param iterable<CustomBusinessActionContract> $customBusinessActions Custom action handler contracts.
     * @param iterable<FieldPresentationContribution> $fieldPresentations Safe presenter declarations.
     * @param   iterable<EventSchemaDefinition>                   $eventSchemas              Versioned event contracts.
     * @param iterable<DomainListenerDefinition> $domainListeners Synchronous listener contracts.
     * @param   iterable<EventConsumerDefinition>                 $eventConsumers            Durable consumer contracts.
     * @param   iterable<JobContributionDefinition>               $jobs                      Job and payload contracts.
     * @param   iterable<QueueContributionDefinition>             $queues                    Logical queue declarations.
     * @param   iterable<ScheduleContributionDefinition>          $schedules                 Recurring schedules.
     * @param   iterable<ProjectionDefinition>                    $projections               Rebuildable projections.
     * @param   iterable<ReportDefinition>                        $reports                   Safe report definitions.
     * @param iterable<WebhookContributionDefinition> $webhooks Outbound adapter declarations.
     * @param   int                                               $spiVersion                Contribution SPI revision.
     * @param   iterable<SurfaceDefinition>                       $interfaceSurfaces         KIS semantic surfaces.
     * @param   iterable<MoneyRateProviderDefinition>             $moneyRateProviders        Exchange-rate sources.
     * @param   iterable<UnitConversionProviderDefinition>        $unitConverters            Unit conversion sources.
     * @param   iterable<TranslationGroupDeclaration>             $contentTranslationGroups  Multilingual content
     *          sets, each naming the locales the package publishes it in and the locale it falls back to.
     * @param   iterable<CompositionBlockDeclaration>             $compositionBlocks         Placeable blocks with
     *          bounded properties, slots and renderer bindings.
     * @param   iterable<CompositionPatternDeclaration>           $compositionPatterns       Reusable structures
     *          arranged from this owner's declared blocks.
     * @param   iterable<CompositionFieldControlDeclaration>      $compositionControls       Editing controls for
     *          published property types.
     * @param   iterable<CompositionInspectorDeclaration>         $compositionInspectors     Inspector panels for
     *          this owner's declared blocks.
     * @param   iterable<CompositionDesignVocabularyDeclaration>  $compositionVocabularies   Design vocabularies
     *          of tokens, recipes and size roles.
     * @param   iterable<CompositionMigrationDeclaration>         $compositionMigrations     Declared migrations for
     *          documents a declared block appears in.
     * @param   iterable<CanonicalCompositionDocument>            $canonicalDocuments        Canonical Studio
     *          documents a schema-6 manifest carries in their exact byte form.
     * @param   iterable<CompositionHostBinding>                  $compositionHostBindings   Bounded host metadata
     *          binding canonical documents into this application.
     *
     * @throws  InvalidArgumentException  When an identifier is outside the owner's namespace or declared twice,
     *          navigation or a route references something this set does not declare, a business definition
     *          names another owner, SPI 2 claims ordering that has no portable storage shape, or a
     *          composition declaration references a renderer or block this set does not own.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ContributionOwner $owner,
        iterable $capabilities = [],
        iterable $workspaces = [],
        iterable $navigation = [],
        iterable $routes = [],
        iterable $views = [],
        iterable $fieldTypes = [],
        iterable $businessDefinitions = [],
        iterable $resourcePolicies = [],
        iterable $portalWorkspaces = [],
        iterable $portalNavigation = [],
        iterable $portalRoutes = [],
        iterable $portalTemplates = [],
        iterable $customBusinessViews = [],
        iterable $customBusinessActions = [],
        iterable $fieldPresentations = [],
        iterable $eventSchemas = [],
        iterable $domainListeners = [],
        iterable $eventConsumers = [],
        iterable $jobs = [],
        iterable $queues = [],
        iterable $schedules = [],
        iterable $projections = [],
        iterable $reports = [],
        iterable $webhooks = [],
        private int $spiVersion = self::SPI_VERSION,
        iterable $interfaceSurfaces = [],
        iterable $moneyRateProviders = [],
        iterable $unitConverters = [],
        iterable $contentTranslationGroups = [],
        iterable $compositionBlocks = [],
        iterable $compositionPatterns = [],
        iterable $compositionControls = [],
        iterable $compositionInspectors = [],
        iterable $compositionVocabularies = [],
        iterable $compositionMigrations = [],
        iterable $canonicalDocuments = [],
        iterable $compositionHostBindings = [],
    ) {
        $supported = [
            self::SPI_VERSION,
            self::CURRENT_SPI_VERSION,
            self::COMPOSITION_SPI_VERSION,
            self::CANONICAL_COMPOSITION_SPI_VERSION,
        ];
        if (!in_array($spiVersion, $supported, true)) {
            throw new InvalidArgumentException('The extension contribution SPI version is unsupported.');
        }
        $this->capabilities = $this->index($capabilities, 'capability');
        $this->resourcePolicies = $this->index($resourcePolicies, 'resource policy');
        $this->workspaces = $this->index($workspaces, 'workspace');
        $this->navigation = $this->index($navigation, 'navigation');
        $this->routes = $this->index($routes, 'route');
        $this->views = $this->index($views, 'view');
        $this->portalWorkspaces = $this->index($portalWorkspaces, 'portal workspace');
        $this->portalNavigation = $this->index($portalNavigation, 'portal navigation');
        $this->portalRoutes = $this->index($portalRoutes, 'portal route');
        $this->portalTemplates = $this->index($portalTemplates, 'portal template');
        $this->interfaceSurfaces = $this->index($interfaceSurfaces, 'interface surface');
        $this->fieldTypes = $this->businessIndex($fieldTypes, 'field type');
        $this->fieldPresentations = $this->fieldPresentationIndex($fieldPresentations);
        $this->businessDefinitions = $this->businessIndex($businessDefinitions, 'business definition');
        $this->customBusinessViews = $this->customContractIndex($customBusinessViews, 'view');
        $this->customBusinessActions = $this->customContractIndex($customBusinessActions, 'action');
        $this->eventSchemas = $this->integrationIndex($eventSchemas, 'event schema');
        $this->domainListeners = $this->integrationIndex($domainListeners, 'domain listener');
        $this->eventConsumers = $this->integrationIndex($eventConsumers, 'event consumer');
        $this->jobs = $this->integrationIndex($jobs, 'job');
        $this->queues = $this->integrationIndex($queues, 'queue');
        $this->schedules = $this->integrationIndex($schedules, 'schedule');
        $this->projections = $this->integrationIndex($projections, 'projection');
        $this->reports = $this->integrationIndex($reports, 'report');
        $this->webhooks = $this->integrationIndex($webhooks, 'webhook');
        $this->moneyRateProviders = $this->index($moneyRateProviders, 'money_rate_provider');
        $this->unitConversionProviders = $this->index($unitConverters, 'unit_conversion_provider');
        $this->contentTranslationGroups = $this->index($contentTranslationGroups, 'content_translation_group');
        $this->compositionBlocks = $this->index($compositionBlocks, 'composition_block');
        $this->compositionPatterns = $this->index($compositionPatterns, 'composition_pattern');
        $this->compositionFieldControls = $this->index($compositionControls, 'composition_field_control');
        $this->compositionInspectors = $this->index($compositionInspectors, 'composition_inspector');
        $this->compositionDesignVocabularies = $this->index(
            $compositionVocabularies,
            'composition_design_vocabulary',
        );
        $this->compositionMigrations = $this->index($compositionMigrations, 'composition_migration');
        $this->canonicalCompositionDocuments = self::canonicalIndex(
            $canonicalDocuments,
            'canonical_composition_document',
        );
        $this->compositionHostBindings = self::canonicalIndex(
            $compositionHostBindings,
            'composition_host_binding',
        );
        if ($this->spiVersion >= self::CURRENT_SPI_VERSION) {
            $this->assertPortableRelationshipOrdering();
        }

        foreach ($this->navigation as $item) {
            if (!isset($this->workspaces[$item->workspace])) {
                throw new InvalidArgumentException('Contributed navigation must reference an owned workspace.');
            }
            if (!isset($this->capabilities[$item->capability])) {
                throw new InvalidArgumentException('Contributed navigation must reference a declared capability.');
            }
        }
        foreach ($this->resourcePolicies as $policy) {
            if (!isset($this->capabilities[$policy->capability])) {
                throw new InvalidArgumentException('A resource policy must reference a declared capability.');
            }
        }
        foreach ($this->routes as $route) {
            if (!isset($this->capabilities[$route->capability])) {
                throw new InvalidArgumentException('Contributed administrator routes require a declared capability.');
            }
            if (!isset($this->views[$route->view])) {
                throw new InvalidArgumentException('Contributed administrator routes must reference a declared view.');
            }
        }
        foreach ($this->portalNavigation as $item) {
            if (!isset($this->portalWorkspaces[$item->workspace])) {
                throw new InvalidArgumentException('Portal navigation must reference an owned portal workspace.');
            }
            if (!isset($this->capabilities[$item->capability])) {
                throw new InvalidArgumentException('Portal navigation must reference a declared capability.');
            }
        }
        foreach ($this->portalRoutes as $route) {
            if (!isset($this->capabilities[$route->capability])) {
                throw new InvalidArgumentException('Contributed portal routes require a declared capability.');
            }
            if (!isset($this->portalTemplates[$route->template])) {
                throw new InvalidArgumentException('Contributed portal routes must reference a declared template.');
            }
        }
        foreach ($this->interfaceSurfaces as $surface) {
            foreach ($surface->declaration->capabilities as $capability) {
                if (!isset($this->capabilities[$capability->value()])) {
                    throw new InvalidArgumentException(
                        'A KIS interface surface must reference a capability declared by its owner.',
                    );
                }
            }
        }
        if ($this->spiVersion >= self::CURRENT_SPI_VERSION || $this->interfaceSurfaces !== []) {
            $this->assertInterfaceRouteCoverage();
        }
        $businessOwner = $owner->identifier() === ContributionOwner::CORE
            ? DefinitionOwner::core()
            : DefinitionOwner::extension($owner->identifier());
        foreach ($this->fieldTypes as $fieldType) {
            $businessOwner->assertOwns($fieldType->id);
        }
        foreach ($this->fieldPresentations as $presentation) {
            $businessOwner->assertOwns($presentation->fieldType);
            if (!isset($this->fieldTypes[$presentation->fieldType])) {
                throw new InvalidArgumentException(
                    'A field-presentation contribution must reference its owner\'s declared field type.',
                );
            }
        }
        foreach ($this->businessDefinitions as $definition) {
            $businessOwner->assertOwns($definition->handle);
            if ($definition->owner->toArray() !== $businessOwner->toArray()) {
                throw new InvalidArgumentException('A business definition contribution has inconsistent ownership.');
            }
            foreach ($definition->views() as $view) {
                if ($view->handler === null || $view->schema === null) {
                    continue;
                }
                $businessOwner->assertOwns($view->handler);
                $businessOwner->assertOwns($view->schema);
                $contract = $this->customBusinessViews[$view->handler] ?? null;
                if ($contract === null || $contract->schema !== $view->schema) {
                    throw new InvalidArgumentException(
                        'A custom business view must reference its owner\'s declared handler contract.',
                    );
                }
            }
            foreach ($definition->actions() as $action) {
                if ($action->handler === null || $action->schema === null) {
                    continue;
                }
                $businessOwner->assertOwns($action->handler);
                $businessOwner->assertOwns($action->schema);
                $contract = $this->customBusinessActions[$action->handler] ?? null;
                if ($contract === null || $contract->schema !== $action->schema) {
                    throw new InvalidArgumentException(
                        'A custom business action must reference its owner\'s declared handler contract.',
                    );
                }
            }
        }
        $customReferences = [];
        foreach (
            [
            'view' => $this->customBusinessViews,
            'action' => $this->customBusinessActions,
            ] as $kind => $contracts
        ) {
            foreach ($contracts as $contract) {
                $businessOwner->assertOwns($contract->handler);
                $businessOwner->assertOwns($contract->schema);
                foreach (['handler' => $contract->handler, 'schema' => $contract->schema] as $role => $reference) {
                    if (isset($customReferences[$reference])) {
                        throw new InvalidArgumentException(sprintf(
                            'Custom business reference %s collides with the declared %s.',
                            $reference,
                            $customReferences[$reference],
                        ));
                    }
                    $customReferences[$reference] = $kind . ' ' . $role;
                }
            }
        }
        $this->assertIntegrationReferences();
        $this->assertCompositionReferences();
        $this->assertCanonicalComposition();
    }

    /**
     * Hold the canonical composition surface to its SPI, its ownership, and its host bindings.
     *
     * Canonical documents belong to SPI 4 alone, exactly as the frozen paraphrase vocabulary
     * belongs to SPI 3: an earlier SPI carrying a canonical document, or SPI 4 carrying a
     * schema-5 declaration, is refused rather than blended. Every document's identity must sit
     * in the owner's Studio namespace, every host binding must name a declared document, a block
     * definition must have exactly one binding carrying an owner-namespaced renderer, and a
     * binding capability must be one this manifest declares.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a document or binding breaks any of those rules.
     *
     * @since   2.0.0
     */
    private function assertCanonicalComposition(): void
    {
        $carriesCanonical = $this->canonicalCompositionDocuments !== [] || $this->compositionHostBindings !== [];
        if ($carriesCanonical && $this->spiVersion < self::CANONICAL_COMPOSITION_SPI_VERSION) {
            throw new InvalidArgumentException(
                'Canonical composition documents require contribution SPI 4.',
            );
        }
        $carriesParaphrase = $this->compositionBlocks !== []
            || $this->compositionPatterns !== []
            || $this->compositionFieldControls !== []
            || $this->compositionInspectors !== []
            || $this->compositionDesignVocabularies !== []
            || $this->compositionMigrations !== [];
        if ($carriesParaphrase && $this->spiVersion >= self::CANONICAL_COMPOSITION_SPI_VERSION) {
            throw new InvalidArgumentException(
                'SPI 4 carries canonical composition documents; the schema-5 vocabulary is frozen at SPI 3.',
            );
        }

        $studioNamespace = ($this->owner->identifier() === ContributionOwner::CORE
            ? ContributionOwner::CORE
            : $this->owner->namespace()) . '/';
        foreach ($this->canonicalCompositionDocuments as $document) {
            if (!str_starts_with($document->identity(), $studioNamespace)) {
                throw new InvalidArgumentException(sprintf(
                    'Canonical composition identity %s must sit in the owner namespace %s.',
                    $document->identity(),
                    $studioNamespace,
                ));
            }
            $ownerDocument = $document->document->owner ?? null;
            $embeddedOwner = $ownerDocument instanceof stdClass ? ($ownerDocument->id ?? null) : null;
            $allowedOwnerNamespaces = $this->owner->identifier() === ContributionOwner::CORE
                ? ['core/', 'studio.core/']
                : [$this->owner->namespace() . '/'];
            if (
                !is_string($embeddedOwner)
                || !array_any(
                    $allowedOwnerNamespaces,
                    static fn (string $namespace): bool => str_starts_with($embeddedOwner, $namespace),
                )
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Canonical composition document owner %s must belong to signed contribution owner %s.',
                    is_string($embeddedOwner) ? $embeddedOwner : '(missing)',
                    $this->owner->identifier(),
                ));
            }
        }
        foreach ($this->compositionHostBindings as $binding) {
            if (!isset($this->canonicalCompositionDocuments[$binding->identifier()])) {
                throw new InvalidArgumentException(
                    'A composition host binding must name a canonical document this manifest declares.',
                );
            }
            if ($binding->renderer !== null) {
                $this->owner->assertOwns($binding->renderer, 'composition renderer');
            }
            if ($binding->capability !== null && !isset($this->capabilities[$binding->capability])) {
                throw new InvalidArgumentException(
                    'A composition host binding capability must be one this manifest declares.',
                );
            }
        }
        foreach ($this->canonicalCompositionDocuments as $identifier => $document) {
            if ($document->kind !== CanonicalCompositionKind::BlockDefinition) {
                continue;
            }
            $binding = $this->compositionHostBindings[$identifier] ?? null;
            if ($binding === null || $binding->renderer === null) {
                throw new InvalidArgumentException(
                    'A canonical block definition requires a host binding with an owner-namespaced renderer.',
                );
            }
        }
    }

    /**
     * Validate references that must stay inside one extension's declared composition surface.
     *
     * A renderer binding must be the owner's to claim, because an unresolvable or foreign binding would
     * surface as a runtime hole once Gate B consumes it. A pattern may only arrange blocks this same
     * manifest declares, an inspector may only open for one of them, and a migration may only step a
     * declared block between revisions the block has actually reached. Running at construction means the
     * same refusals happen at admission and again at install, before any runtime exists.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a renderer, pattern, inspector, or migration reference falls
     *          outside this set's declared blocks or the block's declared revisions.
     *
     * @since   2.0.0
     */
    private function assertCompositionReferences(): void
    {
        foreach ($this->compositionBlocks as $block) {
            $this->owner->assertOwns($block->renderer(), 'composition renderer');
        }
        foreach ($this->compositionPatterns as $pattern) {
            foreach ($pattern->blocks as $reference) {
                if (!isset($this->compositionBlocks[$reference])) {
                    throw new InvalidArgumentException(
                        'A composition pattern must arrange blocks its own manifest declares.',
                    );
                }
            }
        }
        foreach ($this->compositionInspectors as $inspector) {
            if (!isset($this->compositionBlocks[$inspector->block()])) {
                throw new InvalidArgumentException(
                    'A composition inspector must open for a block its own manifest declares.',
                );
            }
        }
        foreach ($this->compositionMigrations as $migration) {
            $block = $this->compositionBlocks[$migration->block()] ?? null;
            if ($block === null) {
                throw new InvalidArgumentException(
                    'A composition migration must step a block its own manifest declares.',
                );
            }
            if ($migration->toVersion() > $block->version()) {
                throw new InvalidArgumentException(
                    'A composition migration cannot target a revision its block has not reached.',
                );
            }
        }
    }

    /**
     * Bind every KIS-enabled graphical route and navigation entry to one semantic surface.
     *
     * Every current-SPI graphical route must have an area-matched surface with the same stable name, and
     * navigation must resolve to that route by path and capability. Mutation routes remain actions of the
     * GET surface rather than pretending to be separate pages. This is intentionally fail-closed for new
     * schema-4 packages while retaining the frozen schema-2 and schema-3 compatibility grammars.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a route, surface, capability, or navigation link is orphaned.
     *
     * @since   2.0.0
     */
    private function assertInterfaceRouteCoverage(): void
    {
        $administrator = [];
        $portal = [];
        foreach ($this->interfaceSurfaces as $identifier => $surface) {
            if ($surface->declaration->area === SurfaceArea::Administrator) {
                $administrator[$identifier] = $surface;
            } elseif ($surface->declaration->area === SurfaceArea::Portal) {
                $portal[$identifier] = $surface;
            }
        }

        foreach ($administrator as $identifier => $surface) {
            $route = $this->routes[$identifier] ?? null;
            if ($route === null || !in_array('GET', $route->methods, true)) {
                throw new InvalidArgumentException(
                    'A KIS administrator surface must match its owned graphical GET route name.',
                );
            }
            $capabilities = array_map(
                static fn (Capability $capability): string => $capability->value(),
                $surface->declaration->capabilities,
            );
            if (!in_array($route->capability, $capabilities, true)) {
                throw new InvalidArgumentException(
                    'A KIS administrator surface must include its route capability.',
                );
            }
        }
        foreach ($portal as $identifier => $surface) {
            $route = $this->portalRoutes[$identifier] ?? null;
            if ($route === null || !in_array('GET', $route->methods, true)) {
                throw new InvalidArgumentException(
                    'A KIS portal surface must match its owned graphical GET route name.',
                );
            }
            $capabilities = array_map(
                static fn (Capability $capability): string => $capability->value(),
                $surface->declaration->capabilities,
            );
            if (!in_array($route->capability, $capabilities, true)) {
                throw new InvalidArgumentException('A KIS portal surface must include its route capability.');
            }
        }
        foreach ($this->routes as $identifier => $route) {
            if (in_array('GET', $route->methods, true) && !isset($administrator[$identifier])) {
                throw new InvalidArgumentException(
                    'A KIS-enabled package must declare every administrator graphical GET route as a surface.',
                );
            }
        }
        foreach ($this->portalRoutes as $identifier => $route) {
            if (in_array('GET', $route->methods, true) && !isset($portal[$identifier])) {
                throw new InvalidArgumentException(
                    'A KIS-enabled package must declare every portal graphical GET route as a surface.',
                );
            }
        }
        foreach ($this->navigation as $item) {
            if ($item->surface === null || !isset($administrator[$item->surface])) {
                throw new InvalidArgumentException(
                    'KIS administrator navigation must declare an admitted interface surface identifier.',
                );
            }
            $matched = array_filter(
                $this->routes,
                static fn (AdministratorRouteDefinition $route): bool =>
                    $route->name === $item->surface
                    && in_array('GET', $route->methods, true)
                    && $route->path === $item->path
                    && $route->capability === $item->capability,
            );
            if ($matched === []) {
                throw new InvalidArgumentException(
                    'KIS administrator navigation must resolve to an owned graphical route and surface.',
                );
            }
        }
        foreach ($this->portalNavigation as $item) {
            if ($item->surface === null || !isset($portal[$item->surface])) {
                throw new InvalidArgumentException(
                    'KIS portal navigation must declare an admitted interface surface identifier.',
                );
            }
            $matched = array_filter(
                $this->portalRoutes,
                static fn (PortalRouteDefinition $route): bool =>
                    $route->name === $item->surface
                    && in_array('GET', $route->methods, true)
                    && $route->path === $item->path
                    && $route->capability === $item->capability,
            );
            if ($matched === []) {
                throw new InvalidArgumentException(
                    'KIS portal navigation must resolve to an owned graphical route and surface.',
                );
            }
        }
    }

    /**
     * Refuse SPI-2 ordering claims that the portable schema compiler cannot materialize.
     *
     * A reciprocal one-to-many/many-to-one pair is stored only in the many-to-one side's direct target
     * column, which has nowhere to retain a collection position. Schema-4 packages therefore have to make
     * an ordered one-to-many inverse-free so it owns a junction table. SPI 1 remains readable exactly as
     * released; narrowing that established contract requires a future versioned compatibility path.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When an ordered one-to-many declaration delegates storage to an
     *          inverse relationship.
     *
     * @since   2.0.0
     */
    private function assertPortableRelationshipOrdering(): void
    {
        foreach ($this->businessDefinitions as $definition) {
            foreach ($definition->relationships() as $relationship) {
                if (
                    $relationship->kind === RelationshipKind::OneToMany
                    && $relationship->ordered
                    && $relationship->inverse !== null
                ) {
                    throw new InvalidArgumentException(
                        'An SPI-2 ordered one-to-many relationship must own inverse-free junction storage.',
                    );
                }
            }
        }
    }

    /**
     * Read a strict manifest's `contributions` object into a checked declaration set.
     *
     * The parsing is deliberately unforgiving, because this is the boundary where untrusted package
     * metadata becomes objects the shell will act on: unknown keys are rejected rather than ignored,
     * every list is capped at 128 entries, every scalar is type-checked, and each identifier is
     * asserted against the declaring package's namespace before the set is assembled.
     *
     * @param   ExtensionIdentifier  $extension       Package the manifest belongs to, which owns everything in it.
     * @param   array<mixed>         $data            The manifest's decoded `contributions` value.
     * @param   int                  $manifestSchema  Manifest grammar: 2 for original typed contributions,
     *          3 for signed presentations/custom handlers, 4 for durable integration contributions, or 5
     *          for composition contributions.
     *
     * @return  self  The package's declarations, indexed and consistency-checked.
     *
     * @throws  InvalidArgumentException  When the SPI version does not match the manifest schema, a value is wrong,
     *          a list is over its cap, an identifier is not the package's to claim, or a published custom field
     *          lacks signed presentation coverage.
     *
     * @since   2.0.0
     */
    public static function fromManifest(ExtensionIdentifier $extension, array $data, int $manifestSchema = 3): self
    {
        if (!in_array($manifestSchema, [2, 3, 4, 5, 6], true)) {
            throw new InvalidArgumentException(
                'Typed extension contributions require manifest schema 2, 3, 4, 5, or 6.',
            );
        }
        $data = self::object($data, 'contributions');
        self::knownKeys(
            $data,
            ExtensionManifestGrammar::contributionKeys($manifestSchema),
            'contributions',
        );
        $expectedSpi = match (true) {
            $manifestSchema >= 6 => self::CANONICAL_COMPOSITION_SPI_VERSION,
            $manifestSchema >= 5 => self::COMPOSITION_SPI_VERSION,
            $manifestSchema >= 4 => self::CURRENT_SPI_VERSION,
            default => self::SPI_VERSION,
        };
        if (($data['version'] ?? null) !== $expectedSpi) {
            throw new InvalidArgumentException(sprintf(
                'Manifest schema %d requires extension contribution SPI version %d.',
                $manifestSchema,
                $expectedSpi,
            ));
        }
        $owner = ContributionOwner::extension($extension->value());
        $administrator = self::object($data['administrator'] ?? [], 'contributions.administrator');
        self::knownKeys($administrator, ['workspaces', 'navigation', 'routes', 'views'], 'administrator contributions');
        $business = self::object($data['business'] ?? [], 'contributions.business');
        self::knownKeys(
            $business,
            ExtensionManifestGrammar::businessKeys($manifestSchema),
            'business contributions',
        );
        $portal = self::object($data['portal'] ?? [], 'contributions.portal');
        self::knownKeys($portal, ['workspaces', 'navigation', 'routes', 'templates'], 'portal contributions');
        $interface = self::object($data['interface'] ?? [], 'contributions.interface');
        self::knownKeys($interface, ['surfaces'], 'interface contributions');
        $content = self::object($data['content'] ?? [], 'contributions.content');
        self::knownKeys(
            $content,
            ExtensionManifestGrammar::contentKeys($manifestSchema),
            'content contributions',
        );
        $composition = self::object($data['composition'] ?? [], 'contributions.composition');
        self::knownKeys(
            $composition,
            ExtensionManifestGrammar::compositionKeys($manifestSchema),
            'composition contributions',
        );
        $integration = self::object($data['integration'] ?? [], 'contributions.integration');
        self::knownKeys(
            $integration,
            ExtensionManifestGrammar::integrationKeys($manifestSchema),
            'integration contributions',
        );

        $capabilities = array_map(static function (array $item) use ($owner): CapabilityDefinition {
            self::knownKeys(
                $item,
                [
                    'id',
                    'label',
                    'description',
                    'allowed_scopes',
                    'delegatable',
                    'high_impact',
                    'lifecycle',
                    'version',
                ],
                'capability contribution',
            );
            $definition = new CapabilityDefinition(
                self::string($item, 'id'),
                self::string($item, 'label'),
                self::string($item, 'description'),
                self::strings($item, 'allowed_scopes', ['global', 'site']),
                self::boolean($item, 'delegatable', true),
                self::boolean($item, 'high_impact', false),
                self::lifecycle($item),
                self::positiveInteger($item, 'version', 1),
            );
            $owner->assertOwns($definition->id, 'capability');
            return $definition;
        }, self::objects($data['capabilities'] ?? [], 'contributions.capabilities'));

        $resourcePolicies = array_map(static function (array $item) use ($owner): ResourcePolicyDefinition {
            self::knownKeys(
                $item,
                [
                    'id',
                    'capability',
                    'resources',
                    'installation_global',
                    'system_identities',
                    'lifecycle',
                    'version',
                ],
                'resource-policy contribution',
            );
            if (self::strings($item, 'system_identities', []) !== []) {
                throw new InvalidArgumentException(
                    'Extension resource policies cannot grant authority to system identities.',
                );
            }
            $resources = array_map(static function (array $resource): ResourcePolicyTarget {
                self::knownKeys($resource, ['type', 'identifiers'], 'resource-policy target');

                return new ResourcePolicyTarget(
                    self::string($resource, 'type'),
                    self::strings($resource, 'identifiers', []),
                );
            }, self::objects($item['resources'] ?? null, 'resource policy resources'));
            $definition = new ResourcePolicyDefinition(
                self::string($item, 'id'),
                self::string($item, 'capability'),
                $resources,
                self::boolean($item, 'installation_global', false),
                [],
                self::lifecycle($item),
                self::positiveInteger($item, 'version', 1),
            );
            $owner->assertOwns($definition->id, 'resource policy');
            $owner->assertOwns($definition->capability, 'capability');

            return $definition;
        }, self::objects($data['resource_policies'] ?? [], 'contributions.resource_policies'));

        $workspaces = array_map(static function (array $item) use ($owner): AdministratorWorkspaceDefinition {
            self::knownKeys($item, ['id', 'label', 'description', 'priority'], 'workspace contribution');
            $definition = new AdministratorWorkspaceDefinition(
                self::string($item, 'id'),
                self::string($item, 'label'),
                self::string($item, 'description'),
                self::integer($item, 'priority'),
            );
            $owner->assertOwns($definition->id, 'workspace');
            return $definition;
        }, self::objects($administrator['workspaces'] ?? [], 'contributions.administrator.workspaces'));

        $navigation = array_map(static function (array $item) use ($owner): AdministratorNavigationDefinition {
            self::knownKeys(
                $item,
                [
                    'id', 'workspace', 'label', 'description', 'path', 'icon', 'capability', 'priority',
                    'keywords', 'surface',
                ],
                'navigation contribution',
            );
            $definition = new AdministratorNavigationDefinition(
                self::string($item, 'id'),
                self::string($item, 'workspace'),
                self::string($item, 'label'),
                self::string($item, 'description'),
                self::string($item, 'path'),
                self::string($item, 'icon'),
                self::string($item, 'capability'),
                self::integer($item, 'priority'),
                self::optionalString($item, 'keywords'),
                ($surface = self::optionalString($item, 'surface')) === '' ? null : $surface,
            );
            $owner->assertOwns($definition->id, 'navigation');
            $owner->assertOwns($definition->workspace, 'workspace');
            $owner->assertOwns($definition->capability, 'capability');
            return $definition;
        }, self::objects($administrator['navigation'] ?? [], 'contributions.administrator.navigation'));

        $routes = array_map(static function (array $item) use ($owner): AdministratorRouteDefinition {
            self::knownKeys($item, ['name', 'path', 'methods', 'capability', 'view'], 'route contribution');
            $methods = $item['methods'] ?? null;
            $definition = new AdministratorRouteDefinition(
                self::string($item, 'name'),
                self::string($item, 'path'),
                is_array($methods) ? $methods : throw new InvalidArgumentException('Route methods must be a list.'),
                self::string($item, 'capability'),
                self::string($item, 'view'),
            );
            $owner->assertOwns($definition->name, 'route');
            $owner->assertOwns($definition->capability, 'capability');
            $owner->assertOwns($definition->view, 'view');
            return $definition;
        }, self::objects($administrator['routes'] ?? [], 'contributions.administrator.routes'));

        $views = array_map(static function (array $item) use ($owner): AdministratorViewDefinition {
            self::knownKeys($item, ['name', 'template'], 'view contribution');
            $definition = new AdministratorViewDefinition(
                self::string($item, 'name'),
                self::string($item, 'template'),
            );
            $owner->assertOwns($definition->name, 'view');
            return $definition;
        }, self::objects($administrator['views'] ?? [], 'contributions.administrator.views'));

        $portalWorkspaces = array_map(static function (array $item) use ($owner): PortalWorkspaceDefinition {
            self::knownKeys($item, ['id', 'label', 'description', 'priority'], 'portal workspace contribution');
            $definition = new PortalWorkspaceDefinition(
                self::string($item, 'id'),
                self::string($item, 'label'),
                self::string($item, 'description'),
                self::integer($item, 'priority'),
            );
            $owner->assertOwns($definition->id, 'portal workspace');
            return $definition;
        }, self::objects($portal['workspaces'] ?? [], 'contributions.portal.workspaces'));

        $portalNavigation = array_map(static function (array $item) use ($owner): PortalNavigationDefinition {
            self::knownKeys(
                $item,
                [
                    'id', 'workspace', 'label', 'description', 'path', 'icon', 'capability', 'priority',
                    'keywords', 'surface',
                ],
                'portal navigation contribution',
            );
            $definition = new PortalNavigationDefinition(
                self::string($item, 'id'),
                self::string($item, 'workspace'),
                self::string($item, 'label'),
                self::string($item, 'description'),
                self::string($item, 'path'),
                self::string($item, 'icon'),
                self::string($item, 'capability'),
                self::integer($item, 'priority'),
                self::optionalString($item, 'keywords'),
                ($surface = self::optionalString($item, 'surface')) === '' ? null : $surface,
            );
            $owner->assertOwns($definition->id, 'portal navigation');
            $owner->assertOwns($definition->workspace, 'portal workspace');
            $owner->assertOwns($definition->capability, 'capability');
            return $definition;
        }, self::objects($portal['navigation'] ?? [], 'contributions.portal.navigation'));

        $portalRoutes = array_map(static function (array $item) use ($owner): PortalRouteDefinition {
            self::knownKeys($item, ['name', 'path', 'methods', 'capability', 'template'], 'portal route contribution');
            $methods = $item['methods'] ?? null;
            $definition = new PortalRouteDefinition(
                self::string($item, 'name'),
                self::string($item, 'path'),
                is_array($methods) ? $methods : throw new InvalidArgumentException('Route methods must be a list.'),
                self::string($item, 'capability'),
                self::string($item, 'template'),
            );
            $owner->assertOwns($definition->name, 'portal route');
            $owner->assertOwns($definition->capability, 'capability');
            $owner->assertOwns($definition->template, 'portal template');
            return $definition;
        }, self::objects($portal['routes'] ?? [], 'contributions.portal.routes'));

        $portalTemplates = array_map(static function (array $item) use ($owner): PortalTemplateDefinition {
            self::knownKeys($item, ['name', 'template'], 'portal template contribution');
            $definition = new PortalTemplateDefinition(
                self::string($item, 'name'),
                self::string($item, 'template'),
            );
            $owner->assertOwns($definition->name, 'portal template');
            return $definition;
        }, self::objects($portal['templates'] ?? [], 'contributions.portal.templates'));
        $interfaceSurfaces = array_map(
            static fn (array $item): SurfaceDefinition => SurfaceDefinition::fromArray($owner, $item),
            self::objects($interface['surfaces'] ?? [], 'contributions.interface.surfaces'),
        );
        if (array_key_exists('interface', $data) && $interfaceSurfaces === []) {
            throw new InvalidArgumentException('A declared KIS interface section requires at least one surface.');
        }

        $businessOwner = DefinitionOwner::extension($extension->value());
        $fieldTypes = array_map(static function (array $item) use ($businessOwner): FieldTypeDefinition {
            $definition = FieldTypeDefinition::fromArray($item);
            $businessOwner->assertOwns($definition->id);
            return $definition;
        }, self::objects($business['field_types'] ?? [], 'contributions.business.field_types'));
        $businessDefinitions = array_map(static function (array $item) use (
            $businessOwner,
        ): EntityTypeDefinition {
            $definition = EntityTypeDefinition::fromArray($item);
            if ($definition->owner->toArray() !== $businessOwner->toArray()) {
                throw new InvalidArgumentException('A contributed business definition must belong to its package.');
            }
            return $definition;
        }, self::objects($business['definitions'] ?? [], 'contributions.business.definitions'));
        $customBusinessViews = array_map(
            static fn (array $item): CustomBusinessViewContract => CustomBusinessViewContract::fromArray($item),
            self::objects($business['view_handlers'] ?? [], 'contributions.business.view_handlers'),
        );
        $customBusinessActions = array_map(
            static fn (array $item): CustomBusinessActionContract => CustomBusinessActionContract::fromArray($item),
            self::objects($business['action_handlers'] ?? [], 'contributions.business.action_handlers'),
        );
        $fieldPresentations = array_map(
            static fn (array $item): FieldPresentationContribution => FieldPresentationContribution::fromArray($item),
            self::objects(
                $business['field_presentations'] ?? [],
                'contributions.business.field_presentations',
            ),
        );
        $eventSchemas = array_map(
            static fn (array $item): EventSchemaDefinition => EventSchemaDefinition::fromArray($item),
            self::objects($integration['event_schemas'] ?? [], 'contributions.integration.event_schemas'),
        );
        $domainListeners = array_map(
            static fn (array $item): DomainListenerDefinition => DomainListenerDefinition::fromArray($item),
            self::objects($integration['domain_listeners'] ?? [], 'contributions.integration.domain_listeners'),
        );
        $eventConsumers = array_map(
            static fn (array $item): EventConsumerDefinition => EventConsumerDefinition::fromArray($item),
            self::objects($integration['consumers'] ?? [], 'contributions.integration.consumers'),
        );
        $jobs = array_map(
            static fn (array $item): JobContributionDefinition => JobContributionDefinition::fromArray($item),
            self::objects($integration['jobs'] ?? [], 'contributions.integration.jobs'),
        );
        $queues = array_map(
            static fn (array $item): QueueContributionDefinition => QueueContributionDefinition::fromArray($item),
            self::objects($integration['queues'] ?? [], 'contributions.integration.queues'),
        );
        $schedules = array_map(
            static fn (array $item): ScheduleContributionDefinition => ScheduleContributionDefinition::fromArray($item),
            self::objects($integration['schedules'] ?? [], 'contributions.integration.schedules'),
        );
        $projections = array_map(
            static fn (array $item): ProjectionDefinition => ProjectionDefinition::fromArray($item),
            self::objects($integration['projections'] ?? [], 'contributions.integration.projections'),
        );
        $reports = array_map(
            static fn (array $item): ReportDefinition => ReportDefinition::fromArray($item),
            self::objects($integration['reports'] ?? [], 'contributions.integration.reports'),
        );
        $webhooks = array_map(
            static fn (array $item): WebhookContributionDefinition => WebhookContributionDefinition::fromArray($item),
            self::objects($integration['webhooks'] ?? [], 'contributions.integration.webhooks'),
        );
        $moneyRateProviders = array_map(
            static fn (array $item): MoneyRateProviderDefinition => MoneyRateProviderDefinition::fromArray($item),
            self::objects($integration['rate_providers'] ?? [], 'contributions.integration.rate_providers'),
        );
        $unitConverters = array_map(
            static fn (array $item): UnitConversionProviderDefinition
                => UnitConversionProviderDefinition::fromArray($item),
            self::objects($integration['unit_converters'] ?? [], 'contributions.integration.unit_converters'),
        );
        $contentTranslationGroups = array_map(
            static fn (array $item): TranslationGroupDeclaration => TranslationGroupDeclaration::fromArray($item),
            self::objects($content['translation_groups'] ?? [], 'contributions.content.translation_groups'),
        );
        $compositionBlocks = array_map(
            static fn (array $item): CompositionBlockDeclaration => CompositionBlockDeclaration::fromArray($item),
            self::objects($composition['blocks'] ?? [], 'contributions.composition.blocks'),
        );
        $compositionPatterns = array_map(
            static fn (array $item): CompositionPatternDeclaration => CompositionPatternDeclaration::fromArray($item),
            self::objects($composition['patterns'] ?? [], 'contributions.composition.patterns'),
        );
        $compositionControls = array_map(
            static fn (array $item): CompositionFieldControlDeclaration
                => CompositionFieldControlDeclaration::fromArray($item),
            self::objects($composition['field_controls'] ?? [], 'contributions.composition.field_controls'),
        );
        $compositionInspectors = array_map(
            static fn (array $item): CompositionInspectorDeclaration
                => CompositionInspectorDeclaration::fromArray($item),
            self::objects($composition['inspectors'] ?? [], 'contributions.composition.inspectors'),
        );
        $compositionVocabularies = array_map(
            static fn (array $item): CompositionDesignVocabularyDeclaration
                => CompositionDesignVocabularyDeclaration::fromArray($item),
            self::objects(
                $composition['design_vocabularies'] ?? [],
                'contributions.composition.design_vocabularies',
            ),
        );
        $compositionMigrations = array_map(
            static fn (array $item): CompositionMigrationDeclaration
                => CompositionMigrationDeclaration::fromArray($item),
            self::objects($composition['migrations'] ?? [], 'contributions.composition.migrations'),
        );
        $canonicalDocuments = [];
        $compositionHostBindings = [];
        if ($manifestSchema >= 6) {
            $schemas = StudioContractSchemas::fromVendoredCorpus();
            $canonicalDocuments = array_map(
                static function (array $item) use ($schemas): CanonicalCompositionDocument {
                    self::knownKeys($item, ['kind', 'canonical'], 'canonical composition document');
                    $kind = CanonicalCompositionKind::tryFrom(self::string($item, 'kind'))
                        ?? throw new InvalidArgumentException(
                            'A canonical composition document names an unknown kind.',
                        );
                    $canonical = $item['canonical'] ?? null;
                    if (!is_string($canonical)) {
                        throw new InvalidArgumentException(
                            'A canonical composition document must carry its canonical JSON string.',
                        );
                    }
                    $document = new CanonicalCompositionDocument($kind, $canonical);
                    $validator = $schemas->validator($kind->value);
                    if (!$validator->validate($document->document)) {
                        $first = $validator->diagnostics()[0] ?? null;
                        throw new InvalidArgumentException(sprintf(
                            'A %s document violates its pinned Studio schema%s.',
                            $kind->value,
                            $first === null ? '' : sprintf(
                                ' (%s at %s)',
                                $first->keyword,
                                $first->instancePath === '' ? 'the document root' : $first->instancePath,
                            ),
                        ));
                    }
                    if ($kind === CanonicalCompositionKind::BlockDefinition) {
                        try {
                            SchemaPropertyProfile::admit($document->document->propertySchema ?? null);
                        } catch (SchemaProfileRejected $rejection) {
                            throw new InvalidArgumentException(sprintf(
                                'A block definition propertySchema falls outside '
                                    . 'studio.profile/schema-property (%s at %s).',
                                $rejection->rejection,
                                $rejection->schemaPath === '' ? 'the schema root' : $rejection->schemaPath,
                            ), 0, $rejection);
                        }
                    }

                    return $document;
                },
                self::objects($composition['documents'] ?? [], 'contributions.composition.documents'),
            );
            $compositionHostBindings = array_map(
                static function (array $item): CompositionHostBinding {
                    self::knownKeys($item, ['kind', 'id', 'renderer', 'capability'], 'composition host binding');
                    $kind = CanonicalCompositionKind::tryFrom(self::string($item, 'kind'))
                        ?? throw new InvalidArgumentException(
                            'A composition host binding names an unknown kind.',
                        );

                    return new CompositionHostBinding(
                        $kind,
                        self::string($item, 'id'),
                        ($item['renderer'] ?? null) !== null ? self::string($item, 'renderer') : null,
                        ($item['capability'] ?? null) !== null ? self::string($item, 'capability') : null,
                    );
                },
                self::objects($composition['host_bindings'] ?? [], 'contributions.composition.host_bindings'),
            );
        }

        $set = new self(
            $owner,
            $capabilities,
            $workspaces,
            $navigation,
            $routes,
            $views,
            $fieldTypes,
            $businessDefinitions,
            $resourcePolicies,
            $portalWorkspaces,
            $portalNavigation,
            $portalRoutes,
            $portalTemplates,
            $customBusinessViews,
            $customBusinessActions,
            $fieldPresentations,
            $eventSchemas,
            $domainListeners,
            $eventConsumers,
            $jobs,
            $queues,
            $schedules,
            $projections,
            $reports,
            $webhooks,
            $expectedSpi,
            $interfaceSurfaces,
            $moneyRateProviders,
            $unitConverters,
            $contentTranslationGroups,
            $compositionBlocks,
            $compositionPatterns,
            $compositionControls,
            $compositionInspectors,
            $compositionVocabularies,
            $compositionMigrations,
            $canonicalDocuments,
            $compositionHostBindings,
        );
        $set->assertFieldPresentationCoverage();

        return $set;
    }

    /**
     * The empty declaration set a schema-1 package stands in with.
     *
     * Schema 1 cannot publish any of these surfaces, so the permission list a caller passes is taken
     * and ignored rather than translated into capabilities. The point is that a legacy package can
     * travel the same code path as a strict one instead of every caller branching on the schema.
     *
     * @param   ExtensionIdentifier  $extension    Package the empty set is attributed to.
     * @param   list<string>         $permissions  The manifest's schema-1 permission codes; not read.
     *
     * @return  self  A set owned by that package and declaring nothing.
     *
     * @since   2.0.0
     */
    public static function legacy(ExtensionIdentifier $extension, array $permissions): self
    {
        $owner = ContributionOwner::extension($extension->value());
        return new self($owner);
    }

    /**
     * The permission codes this package declared.
     *
     * @return  list<CapabilityDefinition>  In capability-identifier order; empty when none were declared.
     *
     * @since   2.0.0
     */
    public function capabilities(): array
    {
        return array_values($this->capabilities);
    }

    /**
     * The owner-bound resource policies this package declared.
     *
     * @return  list<ResourcePolicyDefinition>  In policy-identifier order; empty when none were declared.
     *
     * @since   2.0.0
     */
    public function resourcePolicies(): array
    {
        return array_values($this->resourcePolicies);
    }

    /**
     * The administrator workspaces this package declared.
     *
     * @return  list<AdministratorWorkspaceDefinition>  In workspace-identifier order, not display priority.
     *
     * @since   2.0.0
     */
    public function workspaces(): array
    {
        return array_values($this->workspaces);
    }

    /**
     * The administrator navigation entries this package declared.
     *
     * @return  list<AdministratorNavigationDefinition>  In item-identifier order, not display priority.
     *
     * @since   2.0.0
     */
    public function navigation(): array
    {
        return array_values($this->navigation);
    }

    /**
     * The guarded administrator routes this package declared.
     *
     * @return  list<AdministratorRouteDefinition>  In route-name order; each names a view and capability
     *          declared in this same set.
     *
     * @since   2.0.0
     */
    public function routes(): array
    {
        return array_values($this->routes);
    }

    /**
     * The administrator views this package declared.
     *
     * @return  list<AdministratorViewDefinition>  In view-name order; empty when the package serves no pages.
     *
     * @since   2.0.0
     */
    public function views(): array
    {
        return array_values($this->views);
    }

    /**
     * List the portal workspaces this package declared.
     *
     * @return  list<PortalWorkspaceDefinition>  Workspace declarations in identifier order.
     *
     * @since   2.0.0
     */
    public function portalWorkspaces(): array
    {
        return array_values($this->portalWorkspaces);
    }

    /**
     * List the portal navigation entries this package declared.
     *
     * @return  list<PortalNavigationDefinition>  Navigation declarations in identifier order.
     *
     * @since   2.0.0
     */
    public function portalNavigation(): array
    {
        return array_values($this->portalNavigation);
    }

    /**
     * List the portal routes this package declared.
     *
     * @return  list<PortalRouteDefinition>  Route declarations in route-name order.
     *
     * @since   2.0.0
     */
    public function portalRoutes(): array
    {
        return array_values($this->portalRoutes);
    }

    /**
     * List the portal templates this package declared.
     *
     * @return  list<PortalTemplateDefinition>  Template declarations in name order.
     *
     * @since   2.0.0
     */
    public function portalTemplates(): array
    {
        return array_values($this->portalTemplates);
    }

    /**
     * Return the KIS semantic surfaces this package declared.
     *
     * @return  list<SurfaceDefinition>  Admitted interface declarations in identifier order.
     *
     * @since   2.0.0
     */
    public function interfaceSurfaces(): array
    {
        return array_values($this->interfaceSurfaces);
    }

    /**
     * The field types this package declared.
     *
     * Installation reads this to synchronize the persisted field-type catalog, so it is consulted even
     * for a package that is installed but never activated.
     *
     * @return  list<FieldTypeDefinition>  In field-type-identifier order.
     *
     * @since   2.0.0
     */
    public function fieldTypes(): array
    {
        return array_values($this->fieldTypes);
    }

    /**
     * The entity types this package declared.
     *
     * Installation reads this to synchronize the persisted definition catalog, so it is consulted even
     * for a package that is installed but never activated.
     *
     * @return  list<EntityTypeDefinition>  In definition-handle order.
     *
     * @since   2.0.0
     */
    public function businessDefinitions(): array
    {
        return array_values($this->businessDefinitions);
    }

    /**
     * The safe field-presentation declarations this package signed.
     *
     * @return  list<FieldPresentationContribution>  In field-type identifier order.
     *
     * @since   2.0.0
     */
    public function fieldPresentations(): array
    {
        return array_values($this->fieldPresentations);
    }

    /**
     * The custom business view contracts this package declared.
     *
     * @return  list<CustomBusinessViewContract>  In handler-reference order.
     *
     * @since   2.0.0
     */
    public function customBusinessViews(): array
    {
        return array_values($this->customBusinessViews);
    }

    /**
     * The custom business action contracts this package declared.
     *
     * @return  list<CustomBusinessActionContract>  In handler-reference order.
     *
     * @since   2.0.0
     */
    public function customBusinessActions(): array
    {
        return array_values($this->customBusinessActions);
    }

    /**
     * Return the event schemas carried by this manifest contribution set.
     *
     * @return  list<EventSchemaDefinition>  Versioned event contracts in identifier order.
     *
     * @since   2.0.0
     */
    public function eventSchemas(): array
    {
        return array_values($this->eventSchemas);
    }

    /**
     * Return the domain listeners carried by this manifest contribution set.
     *
     * @return  list<DomainListenerDefinition>  Synchronous listener declarations.
     *
     * @since   2.0.0
     */
    public function domainListeners(): array
    {
        return array_values($this->domainListeners);
    }

    /**
     * Return the event consumers carried by this manifest contribution set.
     *
     * @return  list<EventConsumerDefinition>  Durable consumer declarations.
     *
     * @since   2.0.0
     */
    public function eventConsumers(): array
    {
        return array_values($this->eventConsumers);
    }

    /**
     * Return the jobs carried by this manifest contribution set.
     *
     * @return  list<JobContributionDefinition>  Job handler and payload declarations.
     *
     * @since   2.0.0
     */
    public function jobs(): array
    {
        return array_values($this->jobs);
    }

    /**
     * Return the queues carried by this manifest contribution set.
     *
     * @return  list<QueueContributionDefinition>  Logical queue declarations.
     *
     * @since   2.0.0
     */
    public function queues(): array
    {
        return array_values($this->queues);
    }

    /**
     * Return the schedules carried by this manifest contribution set.
     *
     * @return  list<ScheduleContributionDefinition>  Recurring schedule declarations.
     *
     * @since   2.0.0
     */
    public function schedules(): array
    {
        return array_values($this->schedules);
    }

    /**
     * Return the projections carried by this manifest contribution set.
     *
     * @return  list<ProjectionDefinition>  Rebuildable projection declarations.
     *
     * @since   2.0.0
     */
    public function projections(): array
    {
        return array_values($this->projections);
    }

    /**
     * Return the reports carried by this manifest contribution set.
     *
     * @return  list<ReportDefinition>  Safe report declarations.
     *
     * @since   2.0.0
     */
    public function reports(): array
    {
        return array_values($this->reports);
    }

    /**
     * Return the webhooks carried by this manifest contribution set.
     *
     * @return  list<WebhookContributionDefinition>  Outbound adapter declarations.
     *
     * @since   2.0.0
     */
    public function webhooks(): array
    {
        return array_values($this->webhooks);
    }

    /**
     * Return the money rate providers carried by this manifest contribution set.
     *
     * @return  list<MoneyRateProviderDefinition>  Declared exchange-rate sources.
     *
     * @since   2.0.0
     */
    public function moneyRateProviders(): array
    {
        return array_values($this->moneyRateProviders);
    }

    /**
     * Return the unit conversion providers carried by this manifest contribution set.
     *
     * @return  list<UnitConversionProviderDefinition>  Declared unit conversion sources.
     *
     * @since   2.0.0
     */
    public function unitConversionProviders(): array
    {
        return array_values($this->unitConversionProviders);
    }

    /**
     * Return the multilingual content sets carried by this manifest contribution set.
     *
     * @return  list<TranslationGroupDeclaration>  Declared content sets in identifier order; empty for a
     *          package whose content is published in one language.
     *
     * @since   2.0.0
     */
    public function contentTranslationGroups(): array
    {
        return array_values($this->contentTranslationGroups);
    }

    /**
     * Return the composition blocks carried by this manifest contribution set.
     *
     * @return  list<CompositionBlockDeclaration>  Declared placeable blocks in identifier order; empty for
     *          a package that composes nothing.
     *
     * @since   2.0.0
     */
    public function compositionBlocks(): array
    {
        return array_values($this->compositionBlocks);
    }

    /**
     * Return the composition patterns carried by this manifest contribution set.
     *
     * @return  list<CompositionPatternDeclaration>  Declared reusable structures in identifier order.
     *
     * @since   2.0.0
     */
    public function compositionPatterns(): array
    {
        return array_values($this->compositionPatterns);
    }

    /**
     * Return the composition field controls carried by this manifest contribution set.
     *
     * @return  list<CompositionFieldControlDeclaration>  Declared editing controls in identifier order.
     *
     * @since   2.0.0
     */
    public function compositionFieldControls(): array
    {
        return array_values($this->compositionFieldControls);
    }

    /**
     * Return the composition inspectors carried by this manifest contribution set.
     *
     * @return  list<CompositionInspectorDeclaration>  Declared inspector panels in identifier order.
     *
     * @since   2.0.0
     */
    public function compositionInspectors(): array
    {
        return array_values($this->compositionInspectors);
    }

    /**
     * Return the composition design vocabularies carried by this manifest contribution set.
     *
     * @return  list<CompositionDesignVocabularyDeclaration>  Declared vocabularies in identifier order.
     *
     * @since   2.0.0
     */
    public function compositionDesignVocabularies(): array
    {
        return array_values($this->compositionDesignVocabularies);
    }

    /**
     * Return the composition migrations carried by this manifest contribution set.
     *
     * @return  list<CompositionMigrationDeclaration>  Declared document migrations in identifier order.
     *
     * @since   2.0.0
     */
    public function compositionMigrations(): array
    {
        return array_values($this->compositionMigrations);
    }

    /**
     * Return the canonical Studio composition documents carried by this manifest contribution set.
     *
     * @return  list<CanonicalCompositionDocument>  Declared canonical documents in identifier order.
     *
     * @since   2.0.0
     */
    public function canonicalCompositionDocuments(): array
    {
        return array_values($this->canonicalCompositionDocuments);
    }

    /**
     * Return the bounded host bindings carried by this manifest contribution set.
     *
     * @return  list<CompositionHostBinding>  Declared host metadata in identifier order.
     *
     * @since   2.0.0
     */
    public function compositionHostBindings(): array
    {
        return array_values($this->compositionHostBindings);
    }

    /**
     * Return the SPI version carried by this manifest contribution set.
     *
     * @return  int  Contribution service-provider interface revision.
     *
     * @since   2.0.0
     */
    public function spiVersion(): int
    {
        return $this->spiVersion;
    }

    /**
     * Write the set back out in the same shape `fromManifest()` reads.
     *
     * The runtime publication carries this rather than the original manifest text, so the structure has
     * to round-trip: the compiled map is re-parsed at load and compared with the installed manifest
     * before any of the package's code is allowed to run. Deterministic ordering is what makes that
     * comparison meaningful.
     *
     * @return  array{
     *              version: int,
     *              capabilities: list<array<string, mixed>>,
     *              resource_policies: list<array<string, mixed>>,
     *              administrator: array{
     *                  workspaces: list<array<string, mixed>>,
     *                  navigation: list<array<string, mixed>>,
     *                  routes: list<array<string, mixed>>,
     *                  views: list<array<string, mixed>>
     *              },
     *              interface?: array{surfaces: list<array<string, mixed>>},
     *              content?: array{translation_groups: list<array<string, mixed>>},
     *              composition?: array{
     *                  blocks?: list<array<string, mixed>>,
     *                  patterns?: list<array<string, mixed>>,
     *                  field_controls?: list<array<string, mixed>>,
     *                  inspectors?: list<array<string, mixed>>,
     *                  design_vocabularies?: list<array<string, mixed>>,
     *                  migrations?: list<array<string, mixed>>
     *              },
     *              business: array{
     *                  field_types: list<array<string, mixed>>,
     *                  definitions: list<array<string, mixed>>,
     *                  field_presentations?: list<array<string, mixed>>,
     *                  view_handlers?: list<array<string, mixed>>,
     *                  action_handlers?: list<array<string, mixed>>
     *              }
     *          }
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        $business = [
            'field_types' => array_map(
                static fn (FieldTypeDefinition $item): array => $item->toArray(),
                $this->fieldTypes(),
            ),
            'definitions' => array_map(
                static fn (EntityTypeDefinition $item): array => $item->toArray(),
                $this->businessDefinitions(),
            ),
        ];
        if ($this->fieldPresentations !== []) {
            $business['field_presentations'] = array_map(
                static fn (FieldPresentationContribution $item): array => $item->toArray(),
                $this->fieldPresentations(),
            );
        }
        if ($this->customBusinessViews !== []) {
            $business['view_handlers'] = array_map(
                static fn (CustomBusinessViewContract $item): array => $item->toArray(),
                $this->customBusinessViews(),
            );
        }
        if ($this->customBusinessActions !== []) {
            $business['action_handlers'] = array_map(
                static fn (CustomBusinessActionContract $item): array => $item->toArray(),
                $this->customBusinessActions(),
            );
        }

        $document = [
            'version' => $this->spiVersion,
            'capabilities' => array_map(
                static fn (CapabilityDefinition $item): array => $item->toArray(),
                $this->capabilities(),
            ),
            'resource_policies' => array_map(
                static fn (ResourcePolicyDefinition $item): array => $item->toArray(),
                $this->resourcePolicies(),
            ),
            'administrator' => [
                'workspaces' => array_map(
                    static fn (AdministratorWorkspaceDefinition $item): array => $item->toArray(),
                    $this->workspaces(),
                ),
                'navigation' => array_map(
                    static fn (AdministratorNavigationDefinition $item): array => $item->toArray(),
                    $this->navigation(),
                ),
                'routes' => array_map(
                    static fn (AdministratorRouteDefinition $item): array => $item->toArray(),
                    $this->routes(),
                ),
                'views' => array_map(
                    static fn (AdministratorViewDefinition $item): array => $item->toArray(),
                    $this->views(),
                ),
            ],
            'portal' => [
                'workspaces' => array_map(
                    static fn (PortalWorkspaceDefinition $item): array => $item->toArray(),
                    $this->portalWorkspaces(),
                ),
                'navigation' => array_map(
                    static fn (PortalNavigationDefinition $item): array => $item->toArray(),
                    $this->portalNavigation(),
                ),
                'routes' => array_map(
                    static fn (PortalRouteDefinition $item): array => $item->toArray(),
                    $this->portalRoutes(),
                ),
                'templates' => array_map(
                    static fn (PortalTemplateDefinition $item): array => $item->toArray(),
                    $this->portalTemplates(),
                ),
            ],
            'business' => $business,
        ];
        if ($this->interfaceSurfaces !== []) {
            $document['interface'] = [
                'surfaces' => $this->exports($this->interfaceSurfaces()),
            ];
        }
        if ($this->spiVersion >= self::CURRENT_SPI_VERSION) {
            $document['integration'] = [
                'event_schemas' => $this->exports($this->eventSchemas()),
                'domain_listeners' => $this->exports($this->domainListeners()),
                'consumers' => $this->exports($this->eventConsumers()),
                'jobs' => $this->exports($this->jobs()),
                'queues' => $this->exports($this->queues()),
                'schedules' => $this->exports($this->schedules()),
                'projections' => $this->exports($this->projections()),
                'reports' => $this->exports($this->reports()),
                'webhooks' => $this->exports($this->webhooks()),
            ];
            if ($this->moneyRateProviders !== []) {
                $document['integration']['rate_providers'] = $this->exports($this->moneyRateProviders());
            }
            if ($this->unitConversionProviders !== []) {
                $document['integration']['unit_converters'] = $this->exports($this->unitConversionProviders());
            }
        }
        // Written only when the package declares one, so a manifest that publishes its content in a
        // single language exports the bytes it exported before the locale dimension existed.
        if ($this->contentTranslationGroups !== []) {
            $document['content'] = [
                'translation_groups' => $this->exports($this->contentTranslationGroups()),
            ];
        }
        // Written only when the package composes, and each list only when non-empty, so a package that
        // declares nothing here exports the bytes it exported before the composition surfaces existed.
        $compositionSections = [
            'blocks' => $this->compositionBlocks(),
            'patterns' => $this->compositionPatterns(),
            'field_controls' => $this->compositionFieldControls(),
            'inspectors' => $this->compositionInspectors(),
            'design_vocabularies' => $this->compositionDesignVocabularies(),
            'migrations' => $this->compositionMigrations(),
        ];
        foreach ($compositionSections as $key => $declarations) {
            if ($declarations !== []) {
                $document['composition'][$key] = $this->exports($declarations);
            }
        }
        // Canonical documents export their registry form: the kind-scoped identity is repeated beside
        // the canonical bytes so an inventory reader never decodes a document just to key it.
        if ($this->canonicalCompositionDocuments !== []) {
            $document['composition']['documents'] = array_map(
                static fn (CanonicalCompositionDocument $declared): array => $declared->toArray(),
                $this->canonicalCompositionDocuments(),
            );
        }
        if ($this->compositionHostBindings !== []) {
            $document['composition']['host_bindings'] = array_map(
                static fn (CompositionHostBinding $binding): array => $binding->toArray(),
                $this->compositionHostBindings(),
            );
        }

        return $document;
    }

    /**
     * Export a homogeneous contribution list without repeating closure boilerplate.
     *
     * @param   list<ContributionDefinition>  $definitions  Definitions in deterministic order.
     *
     * @return  list<array<string, mixed>>  Canonical manifest documents.
     *
     * @since   2.0.0
     */
    private function exports(array $definitions): array
    {
        return array_map(
            static fn (ContributionDefinition $definition): array => $definition->toArray(),
            $definitions,
        );
    }

    /**
     * Key one kind of declaration by identifier, refusing anything unowned or repeated.
     *
     * Sorting on the way in is what makes the exports, and therefore reconciliation, independent of the
     * order the manifest happened to list things in.
     *
     * @template T of ContributionDefinition
     *
     * @param   iterable<T>  $items  Declarations of one kind, as the manifest listed them.
     * @param   string       $kind   Kind name used in the ownership check and the failure message.
     *
     * @return  array<string, T>  The declarations keyed by identifier, sorted by that key.
     *
     * @throws  InvalidArgumentException  When an identifier is outside the owner's namespace or repeated.
     *
     * @since   2.0.0
     */
    private function index(iterable $items, string $kind): array
    {
        $result = [];
        foreach ($items as $item) {
            $identifier = $item->identifier();
            $this->owner->assertOwns($identifier, $kind);
            if (isset($result[$identifier])) {
                throw new InvalidArgumentException(sprintf(
                    'Contribution %s %s is declared more than once.',
                    $kind,
                    $identifier,
                ));
            }
            $result[$identifier] = $item;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /**
     * Index canonical composition declarations, whose Studio identity grammar is checked separately.
     *
     * A canonical identity lives inside the portable document and follows the Studio slash grammar,
     * not the dotted App identifier rule, so `assertCanonicalComposition()` owns that check and this
     * index only refuses repetition.
     *
     * @template T of ContributionDefinition
     *
     * @param   iterable<T>  $items  Canonical declarations of one kind, as the manifest listed them.
     * @param   string       $kind   Kind name used in the failure message.
     *
     * @return  array<string, T>  The declarations keyed by identifier, sorted by that key.
     *
     * @throws  InvalidArgumentException  When an identifier is repeated.
     *
     * @since   2.0.0
     */
    private static function canonicalIndex(iterable $items, string $kind): array
    {
        $result = [];
        foreach ($items as $item) {
            $identifier = $item->identifier();
            if (isset($result[$identifier])) {
                throw new InvalidArgumentException(sprintf(
                    'Contribution %s %s is declared more than once.',
                    $kind,
                    $identifier,
                ));
            }
            $result[$identifier] = $item;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Key business declarations the same way, reading the identifier field each type actually has.
     *
     * Ownership is deliberately not checked here: business identifiers belong to a `DefinitionOwner`,
     * so the constructor asserts them against that owner once both indexes exist.
     *
     * @template T of FieldTypeDefinition|EntityTypeDefinition
     *
     * @param   iterable<T>  $items  Declared field types or entity types, as the manifest listed them.
     * @param   string       $kind   Kind name used in the failure message.
     *
     * @return  array<string, T>  The declarations keyed by id or handle, sorted by that key.
     *
     * @throws  InvalidArgumentException  When the same id or handle is declared more than once.
     *
     * @since   2.0.0
     */
    private function businessIndex(iterable $items, string $kind): array
    {
        $result = [];
        foreach ($items as $item) {
            $identifier = $item instanceof FieldTypeDefinition ? $item->id : $item->handle;
            if (isset($result[$identifier])) {
                throw new InvalidArgumentException(sprintf(
                    'Contribution %s %s is declared more than once.',
                    $kind,
                    $identifier,
                ));
            }
            $result[$identifier] = $item;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /**
     * Key one integration declaration and require its embedded owner to match the registrar owner.
     *
     * Report definitions carry ownership through their identifier and therefore have no duplicate
     * owner field. Every other integration contract includes the owner in its signed bytes, so a
     * package cannot present a byte-equivalent identifier while attributing the behavior elsewhere.
     *
     * @template T of ContributionDefinition
     *
     * @param   iterable<T>  $items  Integration declarations of one kind.
     * @param   string       $kind   Kind used for ownership and duplicate diagnostics.
     *
     * @return  array<string, T>  Definitions keyed in deterministic identifier order.
     *
     * @throws  InvalidArgumentException  When ownership is inconsistent or an identifier repeats.
     *
     * @since   2.0.0
     */
    private function integrationIndex(iterable $items, string $kind): array
    {
        $result = $this->index($items, $kind);
        foreach ($result as $definition) {
            $document = $definition->toArray();
            if (
                array_key_exists('owner', $document)
                && $document['owner'] !== $this->owner->identifier()
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Contribution %s %s has inconsistent ownership.',
                    $kind,
                    $definition->identifier(),
                ));
            }
        }

        return $result;
    }

    /**
     * Validate references that must stay inside one extension's declared automation graph.
     *
     * Consumers, listeners, projections and outbound adapters may subscribe to a core or another
     * package's public event contract, so those event references are resolved against the complete
     * runtime catalog later. A schedule may execute only an owned declared job, reports may read only
     * owned business definitions, and non-default queues must be declared by this package.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When an owned job, queue, report source, or capability reference is absent.
     *
     * @since   2.0.0
     */
    private function assertIntegrationReferences(): void
    {
        foreach ($this->jobs as $job) {
            $this->assertQueueReference($job->toArray()['queue'] ?? null, 'job');
        }
        foreach ($this->eventConsumers as $consumer) {
            $this->assertQueueReference($consumer->toArray()['queue'] ?? null, 'event consumer');
        }
        foreach ($this->schedules as $schedule) {
            $document = $schedule->toArray();
            $jobType = $document['job_type'] ?? null;
            if (!is_string($jobType) || !isset($this->jobs[$jobType])) {
                throw new InvalidArgumentException('A contributed schedule must reference an owned declared job.');
            }
            $job = $this->jobs[$jobType];
            if (!$job instanceof JobContributionDefinition || !$schedule instanceof ScheduleContributionDefinition) {
                throw new InvalidArgumentException('A contributed schedule or job definition is invalid.');
            }
            if ($job->installationWide() === ($schedule->siteIdentifier() !== null)) {
                throw new InvalidArgumentException(
                    'A contributed schedule site must agree with its job execution scope.',
                );
            }
            (new PayloadSchemaValidator())->assertPayload($job->payloadSchema(), $schedule->payload());
            $this->assertQueueReference($document['queue'] ?? null, 'schedule');
        }
        foreach ($this->webhooks as $webhook) {
            $this->assertQueueReference($webhook->toArray()['queue'] ?? null, 'webhook');
        }
        foreach ($this->reports as $report) {
            $document = $report->toArray();
            $source = $document['source_definition'] ?? null;
            if (!is_string($source)) {
                throw new InvalidArgumentException('A contributed report source definition is invalid.');
            }
            $this->owner->assertOwns($source, 'report source');
            $capability = $document['required_capability'] ?? null;
            if (!is_string($capability) || !isset($this->capabilities[$capability])) {
                throw new InvalidArgumentException('A contributed report must reference a declared capability.');
            }
        }
    }

    /**
     * Accept the platform default queue or require an owner-declared queue identifier.
     *
     * @param   mixed   $queue  Queue value read from a canonical contribution document.
     * @param   string  $kind   Referencing contribution kind.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the queue is invalid or unavailable.
     *
     * @since   2.0.0
     */
    private function assertQueueReference(mixed $queue, string $kind): void
    {
        if (!is_string($queue) || ($queue !== 'default' && !isset($this->queues[$queue]))) {
            throw new InvalidArgumentException(sprintf(
                'A contributed %s must reference the default or an owned declared queue.',
                $kind,
            ));
        }
    }

    /**
     * Key safe field-presentation declarations by their owned field type.
     *
     * @param   iterable<FieldPresentationContribution>  $items  Signed presenter declarations.
     *
     * @return  array<string, FieldPresentationContribution>  Declarations in field-type order.
     *
     * @throws  InvalidArgumentException  When one field type is declared more than once.
     *
     * @since   2.0.0
     */
    private function fieldPresentationIndex(iterable $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (isset($result[$item->fieldType])) {
                throw new InvalidArgumentException(sprintf(
                    'Field presentation %s is declared more than once.',
                    $item->fieldType,
                ));
            }
            $result[$item->fieldType] = $item;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Prove published generated surfaces cannot outlive their custom field-type presenters.
     *
     * Install and runtime publication both parse the signed manifest through this method. Requiring every
     * package-owned custom type used by a published definition to cover its potentially visible render and
     * edit contexts therefore fails before persistence or provider code, instead of waiting for the first
     * administrator, portal, or public render to discover a missing strategy.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a published definition uses an owned custom field type without
     *          signed coverage for every context that field can reach.
     *
     * @since   2.0.0
     */
    private function assertFieldPresentationCoverage(): void
    {
        /** @var array<string, array<string, true>> $required */
        $required = [];
        foreach ($this->businessDefinitions as $definition) {
            if ($definition->status !== DefinitionStatus::Published) {
                continue;
            }
            foreach ($definition->fields() as $field) {
                if (!isset($this->fieldTypes[$field->type])) {
                    continue;
                }
                foreach (FieldPresentationCoverage::requiredContexts($field) as $context) {
                    $required[$field->type][$context->value] = true;
                }
            }
        }
        foreach ($required as $fieldType => $contexts) {
            $declared = [];
            $presentation = $this->fieldPresentations[$fieldType] ?? null;
            $presentationContexts = $presentation === null ? [] : $presentation->contexts;
            foreach ($presentationContexts as $context) {
                $declared[$context->value] = true;
            }
            $missing = array_keys(array_diff_key($contexts, $declared));
            if ($missing === []) {
                continue;
            }
            sort($missing, SORT_STRING);
            throw new InvalidArgumentException(sprintf(
                'Published business definitions require signed presentation contexts for %s: %s.',
                $fieldType,
                implode(', ', $missing),
            ));
        }
    }

    /**
     * Key custom handler contracts by handler reference and reject handler or schema duplication.
     *
     * @template T of CustomBusinessViewContract|CustomBusinessActionContract
     *
     * @param   iterable<T>  $items  Signed custom handler contracts as declared.
     * @param   string       $kind   View or action kind used in stable failures.
     *
     * @return  array<string, T>  Contracts keyed and sorted by handler reference.
     *
     * @throws  InvalidArgumentException  When a handler or schema reference repeats within this kind.
     *
     * @since   2.0.0
     */
    private function customContractIndex(iterable $items, string $kind): array
    {
        $result = [];
        $schemas = [];
        foreach ($items as $item) {
            if (isset($result[$item->handler])) {
                throw new InvalidArgumentException(sprintf(
                    'Custom business %s handler %s is declared more than once.',
                    $kind,
                    $item->handler,
                ));
            }
            if (isset($schemas[$item->schema])) {
                throw new InvalidArgumentException(sprintf(
                    'Custom business %s schema %s is declared more than once.',
                    $kind,
                    $item->schema,
                ));
            }
            $result[$item->handler] = $item;
            $schemas[$item->schema] = true;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /**
     * Insist a decoded manifest value is a JSON object rather than a list.
     *
     * An empty array decodes ambiguously, so it is accepted as the empty object an omitted section
     * would have produced.
     *
     * @param   mixed   $value  Decoded manifest value to check.
     * @param   string  $field  Dotted manifest path, used only to name the field in the failure message.
     *
     * @return  array<string, mixed>  The same value, now known to be keyed.
     *
     * @throws  InvalidArgumentException  When the value is not an array, or is a non-empty list.
     *
     * @since   2.0.0
     */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || (array_is_list($value) && $value !== [])) {
            throw new InvalidArgumentException(sprintf('%s must be an object.', $field));
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Insist a decoded manifest value is a bounded list of JSON objects.
     *
     * The 128-entry cap is a denial-of-service guard: manifest parsing happens before the package is
     * trusted, so a declaration list is never allowed to be unbounded work.
     *
     * @param   mixed   $value  Decoded manifest value to check.
     * @param   string  $field  Dotted manifest path, used only to name the field in the failure message.
     *
     * @return  list<array<string, mixed>>  The entries in manifest order, each known to be keyed.
     *
     * @throws  InvalidArgumentException  When the value is not a list, holds more than 128 entries, or
     *          contains anything that is not an object.
     *
     * @since   2.0.0
     */
    private static function objects(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 128) {
            throw new InvalidArgumentException(sprintf('%s must be a list of at most 128 objects.', $field));
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new InvalidArgumentException(sprintf('Every %s entry must be an object.', $field));
            }
            /** @var array<string, mixed> $item */
            $result[] = $item;
        }
        return $result;
    }

    /**
     * Reject a manifest object carrying any key this version does not understand.
     *
     * Refusing the unknown rather than ignoring it means a package built against a later SPI fails
     * visibly at install instead of silently losing the part of its declaration nothing here reads.
     * The first unknown key in sorted order is named, so the message is stable across runs.
     *
     * @param   array<string, mixed>  $values   Decoded manifest object to inspect.
     * @param   list<string>          $allowed  Every key this version accepts at that position.
     * @param   string                $field    Manifest section named in the failure message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When any key falls outside the allowed set.
     *
     * @since   2.0.0
     */
    private static function knownKeys(array $values, array $allowed, string $field): void
    {
        $unknown = array_diff(array_keys($values), $allowed);
        if ($unknown !== []) {
            sort($unknown, SORT_STRING);
            throw new InvalidArgumentException(sprintf('%s contains unknown key %s.', $field, $unknown[0]));
        }
    }

    /**
     * Read a required non-empty string field out of a decoded manifest object.
     *
     * @param   array<string, mixed>  $values  Decoded manifest object holding the field.
     * @param   string                $field   Key to read, also named in the failure message.
     *
     * @return  string  The value with surrounding whitespace removed.
     *
     * @throws  InvalidArgumentException  When the key is absent, not a string, or blank once trimmed.
     *
     * @since   2.0.0
     */
    private static function string(array $values, string $field): string
    {
        $value = $values[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Contribution field %s must be a non-empty string.', $field));
        }
        return trim($value);
    }

    /**
     * Read an optional string field, treating absence and emptiness alike.
     *
     * @param   array<string, mixed>  $values  Decoded manifest object that may hold the field.
     * @param   string                $field   Key to read, also named in the failure message.
     *
     * @return  string  The trimmed value, or an empty string when the key was not present.
     *
     * @throws  InvalidArgumentException  When the key is present but not a string.
     *
     * @since   2.0.0
     */
    private static function optionalString(array $values, string $field): string
    {
        $value = $values[$field] ?? '';
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Contribution field %s must be a string.', $field));
        }
        return trim($value);
    }

    /**
     * Read an optional bounded list of non-empty strings from a decoded manifest object.
     *
     * @param   array<string, mixed>  $values   Object that may hold the list.
     * @param   string                $field    Key to read and name in a failure.
     * @param   list<string>          $default  Value returned when the key is absent.
     *
     * @return  list<string>  Trimmed strings in declaration order.
     *
     * @throws  InvalidArgumentException  When the value is not a list of at most 128 non-empty strings.
     *
     * @since   2.0.0
     */
    private static function strings(array $values, string $field, array $default): array
    {
        $value = $values[$field] ?? $default;
        if (!is_array($value) || !array_is_list($value) || count($value) > 128) {
            throw new InvalidArgumentException(sprintf('Contribution field %s must be a bounded string list.', $field));
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(sprintf(
                    'Every contribution field %s entry must be a non-empty string.',
                    $field,
                ));
            }
            $result[] = trim($item);
        }

        return $result;
    }

    /**
     * Read an optional strict boolean from a decoded manifest object.
     *
     * @param   array<string, mixed>  $values   Object that may hold the value.
     * @param   string                $field    Key to read and name in a failure.
     * @param   bool                  $default  Value returned when the key is absent.
     *
     * @return  bool  Decoded boolean without scalar coercion.
     *
     * @throws  InvalidArgumentException  When a present value is not a boolean.
     *
     * @since   2.0.0
     */
    private static function boolean(array $values, string $field, bool $default): bool
    {
        $value = $values[$field] ?? $default;
        if (!is_bool($value)) {
            throw new InvalidArgumentException(sprintf('Contribution field %s must be a boolean.', $field));
        }

        return $value;
    }

    /**
     * Read a capability or policy lifecycle, defaulting an omitted value to active.
     *
     * @param   array<string, mixed>  $values  Declaration object that may carry `lifecycle`.
     *
     * @return  AuthorizationDefinitionLifecycle  Validated lifecycle enum case.
     *
     * @throws  InvalidArgumentException  When the value is not a recognized lifecycle string.
     *
     * @since   2.0.0
     */
    private static function lifecycle(array $values): AuthorizationDefinitionLifecycle
    {
        $value = $values['lifecycle'] ?? AuthorizationDefinitionLifecycle::Active->value;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Contribution field lifecycle must be a string.');
        }
        $lifecycle = AuthorizationDefinitionLifecycle::tryFrom($value);
        if ($lifecycle === null) {
            throw new InvalidArgumentException('Contribution field lifecycle is not recognized.');
        }

        return $lifecycle;
    }

    /**
     * Read an optional positive integer from a decoded manifest object.
     *
     * @param   array<string, mixed>  $values   Object that may hold the value.
     * @param   string                $field    Key to read and name in a failure.
     * @param   int                   $default  Positive value returned when the key is absent.
     *
     * @return  int  Strict positive integer without numeric-string coercion.
     *
     * @throws  InvalidArgumentException  When a present value is not a positive integer.
     *
     * @since   2.0.0
     */
    private static function positiveInteger(array $values, string $field, int $default): int
    {
        $value = $values[$field] ?? $default;
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException(sprintf('Contribution field %s must be a positive integer.', $field));
        }

        return $value;
    }

    /**
     * Read a required integer field out of a decoded manifest object.
     *
     * A numeric string is not coerced, so a priority written as `"10"` in a manifest is a declaration
     * error rather than a silently accepted value.
     *
     * @param   array<string, mixed>  $values  Decoded manifest object holding the field.
     * @param   string                $field   Key to read, also named in the failure message.
     *
     * @return  int  The value exactly as decoded.
     *
     * @throws  InvalidArgumentException  When the key is absent or is not an integer.
     *
     * @since   2.0.0
     */
    private static function integer(array $values, string $field): int
    {
        $value = $values[$field] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('Contribution field %s must be an integer.', $field));
        }
        return $value;
    }
}
