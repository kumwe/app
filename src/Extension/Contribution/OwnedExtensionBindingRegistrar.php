<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Runtime\TrustEnforcingJobHandler;
use Kumwe\App\Extension\Runtime\TrustEnforcingStudioPreviewBlockRenderer;
use Kumwe\Conversion\Provider\MoneyRateProvider;
use Kumwe\Conversion\Provider\UnitConversionProvider;
use Kumwe\Extension\Manifest\ManifestContributions;
use Kumwe\Extension\Spi\Application\Automation\JobHandler;
use Kumwe\Extension\Spi\Binding\ExecutableBindingKind;
use Kumwe\Extension\Spi\Binding\ExtensionBindingRegistrar;
use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteHandlerFactory;
use Kumwe\Extension\Spi\Binding\Http\PortalRouteHandlerFactory;
use Kumwe\Extension\Spi\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionBuilder;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionHandler;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewHandler;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresenter;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionKind;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionDocument;
use Kumwe\Extension\Spi\Contribution\ContributionDefinition;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockRenderer;
use LogicException;

/**
 * Owner-scoped executable sink for the exact requirements of one canonical signed manifest.
 *
 * The registrar accepts behavior only. Every declaration is resolved from `ManifestContributions`,
 * every attempted identifier is checked against its SDK-generated executable inventory, and
 * `complete()` requires the signed set to be satisfied exactly once.
 *
 * @since  2.0.0
 */
final class OwnedExtensionBindingRegistrar implements ExtensionBindingRegistrar
{
    /**
     * Successfully bound identifiers keyed by the canonical executable kind.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private array $bound = [];

    /**
     * Whether exact binding reconciliation has completed.
     *
     * @var    bool
     * @since  2.0.0
     */
    private bool $closed = false;

    /**
     * App-owned semantic interpretation of the same canonical SDK graph.
     *
     * @var    CanonicalManifestInterpreter
     * @since  2.0.0
     */
    private readonly CanonicalManifestInterpreter $host;

    /**
     * Open one exact executable binding phase.
     *
     * @param  ManifestContributions             $manifest        Canonical package-owned declaration graph.
     * @param  ExtensionContributionRegistrySet  $registries      Host registries receiving admitted behavior.
     * @param  ?TrustStore                       $trust           Live trust boundary required by preview and job code.
     * @param  ?ExtensionExecutionGate           $execution       Runtime-generation boundary for preview and job code.
     * @param  ?string                           $runtimeVersion  Exact signed package version.
     * @param  array<string, mixed>|null         $runtimeEntry    Exact signed runtime-map entry.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly ManifestContributions $manifest,
        private readonly ExtensionContributionRegistrySet $registries,
        private readonly ?TrustStore $trust = null,
        private readonly ?ExtensionExecutionGate $execution = null,
        private readonly ?string $runtimeVersion = null,
        private readonly ?array $runtimeEntry = null,
    ) {
        $this->host = new CanonicalManifestInterpreter($manifest);
    }

    /**
     * Bind a presenter to its signed field-type identifier.
     *
     * @param   string          $fieldType  Canonical manifest field-type identifier.
     * @param   FieldPresenter  $presenter  Host-neutral executable presenter.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function fieldPresenter(string $fieldType, FieldPresenter $presenter): void
    {
        $kind = ExecutableBindingKind::FieldPresenter;
        $this->assertBindable($kind, $fieldType);
        $definition = $this->manifest->fieldPresentation($fieldType)
            ?? throw new LogicException('A declared field-presentation binding lost its canonical definition.');
        $this->registries->fieldPresentations()->register(
            DefinitionOwner::extension($this->manifest->owner->identifier()),
            $fieldType,
            $definition->contexts,
            $presenter,
        );
        $this->record($kind, $fieldType);
    }

    /**
     * Bind a money-rate implementation to its signed provider identifier.
     *
     * @param   string             $identifier  Canonical manifest provider identifier.
     * @param   MoneyRateProvider  $provider    Conversion package implementation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function moneyRateProvider(string $identifier, MoneyRateProvider $provider): void
    {
        $kind = ExecutableBindingKind::MoneyRateProvider;
        $this->assertBindable($kind, $identifier);
        if ($provider->identifier() !== $identifier) {
            throw new InvalidArgumentException('A money-rate provider identity contradicts its signed declaration.');
        }
        $definition = $this->definition($this->host->moneyRateProviders(), $identifier, 'money-rate provider');
        $this->registries->moneyRateProviders()->register($this->manifest->owner, $definition, $provider);
        $this->record($kind, $identifier);
    }

    /**
     * Bind a unit-conversion implementation to its signed provider identifier.
     *
     * @param   string                  $identifier  Canonical manifest provider identifier.
     * @param   UnitConversionProvider  $provider    Conversion package implementation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function unitConversionProvider(string $identifier, UnitConversionProvider $provider): void
    {
        $kind = ExecutableBindingKind::UnitConversionProvider;
        $this->assertBindable($kind, $identifier);
        if ($provider->identifier() !== $identifier) {
            throw new InvalidArgumentException('A unit provider identity contradicts its signed declaration.');
        }
        $definition = $this->definition($this->host->unitConversionProviders(), $identifier, 'unit provider');
        $this->registries->unitConversionProviders()->register($this->manifest->owner, $definition, $provider);
        $this->record($kind, $identifier);
    }

    /**
     * Bind a custom-view implementation to its signed handler identifier.
     *
     * @param   string                     $identifier  Canonical manifest handler identifier.
     * @param   CustomBusinessViewHandler  $handler     SDK executable view handler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function customBusinessViewHandler(string $identifier, CustomBusinessViewHandler $handler): void
    {
        $kind = ExecutableBindingKind::CustomBusinessViewHandler;
        $this->assertBindable($kind, $identifier);
        foreach ($this->host->customBusinessViews() as $contract) {
            if ($contract->handler !== $identifier) {
                continue;
            }
            $this->registries->customBusinessViewHandlers()->register(
                DefinitionOwner::extension($this->manifest->owner->identifier()),
                $contract,
                $handler,
            );
            $this->record($kind, $identifier);

            return;
        }
        throw new LogicException('A declared custom-view binding lost its host policy contract.');
    }

    /**
     * Bind a custom-action implementation to its signed handler identifier.
     *
     * @param   string                       $identifier  Canonical manifest handler identifier.
     * @param   CustomBusinessActionHandler  $handler     SDK executable action handler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function customBusinessActionHandler(string $identifier, CustomBusinessActionHandler $handler): void
    {
        $kind = ExecutableBindingKind::CustomBusinessActionHandler;
        $this->assertBindable($kind, $identifier);
        foreach ($this->host->customBusinessActions() as $contract) {
            if ($contract->handler !== $identifier) {
                continue;
            }
            $this->registries->customBusinessActionHandlers()->register(
                DefinitionOwner::extension($this->manifest->owner->identifier()),
                $contract,
                $handler,
            );
            $this->record($kind, $identifier);

            return;
        }
        throw new LogicException('A declared custom-action binding lost its host policy contract.');
    }

    /**
     * Bind an administrator handler factory to its signed route name.
     *
     * @param   string                            $name     Canonical manifest route name.
     * @param   AdministratorRouteHandlerFactory  $factory  SDK route factory.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function administratorRoute(string $name, AdministratorRouteHandlerFactory $factory): void
    {
        $kind = ExecutableBindingKind::AdministratorRoute;
        $this->assertBindable($kind, $name);
        $definition = $this->manifest->administratorRoute($name)
            ?? throw new LogicException('A declared administrator route lost its canonical definition.');
        $this->registries->routes()->register($this->manifest->owner, $definition, $factory);
        $this->record($kind, $name);
    }

    /**
     * Bind a portal handler factory to its signed route name.
     *
     * @param   string                     $name     Canonical manifest route name.
     * @param   PortalRouteHandlerFactory  $factory  SDK route factory.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function portalRoute(string $name, PortalRouteHandlerFactory $factory): void
    {
        $kind = ExecutableBindingKind::PortalRoute;
        $this->assertBindable($kind, $name);
        $definition = $this->manifest->portalRoute($name)
            ?? throw new LogicException('A declared portal route lost its canonical definition.');
        $this->registries->portalRoutes()->register($this->manifest->owner, $definition, $factory);
        $this->record($kind, $name);
    }

    /**
     * Bind a synchronous listener to its signed identifier.
     *
     * @param   string              $identifier  Canonical manifest listener identifier.
     * @param   DomainEventHandler  $handler     SDK domain-event executable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function domainListener(string $identifier, DomainEventHandler $handler): void
    {
        $kind = ExecutableBindingKind::DomainListener;
        $this->assertBindable($kind, $identifier);
        $definition = $this->manifest->domainListener($identifier)
            ?? throw new LogicException('A declared domain listener lost its canonical definition.');
        $this->registries->domainListeners()->register($this->manifest->owner, $definition, $handler);
        $this->record($kind, $identifier);
    }

    /**
     * Bind a durable event consumer to its signed identifier.
     *
     * @param   string                   $identifier  Canonical manifest consumer identifier.
     * @param   IntegrationEventHandler  $handler     SDK integration-event executable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function eventConsumer(string $identifier, IntegrationEventHandler $handler): void
    {
        $kind = ExecutableBindingKind::EventConsumer;
        $this->assertBindable($kind, $identifier);
        $definition = $this->manifest->eventConsumer($identifier)
            ?? throw new LogicException('A declared event consumer lost its canonical definition.');
        $this->registries->eventConsumers()->register($this->manifest->owner, $definition, $handler);
        $this->record($kind, $identifier);
    }

    /**
     * Bind a job executable to its signed job type behind the live trust and boot-generation fence.
     *
     * @param   string      $identifier  Canonical manifest job type.
     * @param   JobHandler  $handler     SDK job executable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function jobHandler(string $identifier, JobHandler $handler): void
    {
        $kind = ExecutableBindingKind::JobHandler;
        $this->assertBindable($kind, $identifier);
        if ($this->trust === null || $this->execution === null || $this->runtimeEntry === null) {
            throw new LogicException('A contributed job handler requires exact signed runtime provenance.');
        }
        $definition = $this->manifest->job($identifier)
            ?? throw new LogicException('A declared job lost its canonical definition.');
        $this->registries->jobs()->register(
            $this->manifest->owner,
            $definition,
            new TrustEnforcingJobHandler(
                $handler,
                $this->trust,
                $this->execution,
                $this->manifest->owner->identifier(),
                $this->runtimeEntry,
            ),
        );
        $this->record($kind, $identifier);
    }

    /**
     * Bind a projection builder to its signed projection identifier.
     *
     * @param   string             $identifier  Canonical manifest projection identifier.
     * @param   ProjectionBuilder  $builder     SDK deterministic projection executable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function projection(string $identifier, ProjectionBuilder $builder): void
    {
        $kind = ExecutableBindingKind::Projection;
        $this->assertBindable($kind, $identifier);
        $definition = $this->manifest->projection($identifier)
            ?? throw new LogicException('A declared projection lost its canonical definition.');
        $this->registries->projections()->register($this->manifest->owner, $definition, $builder);
        $this->record($kind, $identifier);
    }

    /**
     * Bind an outbound transport to its signed webhook adapter identifier.
     *
     * @param   string                     $identifier  Canonical manifest adapter identifier.
     * @param   IntegrationEventTransport  $transport   SDK outbound executable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function webhook(string $identifier, IntegrationEventTransport $transport): void
    {
        $kind = ExecutableBindingKind::Webhook;
        $this->assertBindable($kind, $identifier);
        $definition = $this->manifest->webhook($identifier)
            ?? throw new LogicException('A declared webhook lost its canonical definition.');
        $this->registries->webhooks()->register($this->manifest->owner, $definition, $transport);
        $this->record($kind, $identifier);
    }

    /**
     * Bind one owner-local preview renderer to every signed block that names it.
     *
     * @param   string                      $identifier  Canonical manifest renderer identifier.
     * @param   StudioPreviewBlockRenderer  $renderer    Frozen SDK preview SPI executable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function studioPreviewRenderer(string $identifier, StudioPreviewBlockRenderer $renderer): void
    {
        $kind = ExecutableBindingKind::StudioPreviewRenderer;
        $this->assertBindable($kind, $identifier);
        if (
            $this->trust === null
            || $this->execution === null
            || $this->runtimeVersion === null
            || $this->runtimeEntry === null
        ) {
            throw new LogicException('A Studio preview renderer requires exact signed runtime provenance.');
        }

        $documents = [];
        foreach ($this->manifest->canonicalCompositionDocuments() as $document) {
            $documents[$document->identifier()] = $document;
        }
        $registered = 0;
        foreach ($this->manifest->compositionHostBindings() as $binding) {
            if ($binding->kind !== CanonicalCompositionKind::BlockDefinition || $binding->renderer !== $identifier) {
                continue;
            }
            $document = $documents[$binding->identifier()] ?? null;
            if (!$document instanceof CanonicalCompositionDocument) {
                throw new LogicException('A Studio renderer binding lost its canonical block document.');
            }
            $definition = new StudioPreviewRendererContribution(
                $this->manifest->owner,
                $this->runtimeVersion,
                $document,
                $binding,
            );
            $this->registries->studioPreviewRenderers()->register(
                $this->manifest->owner,
                $definition,
                new TrustEnforcingStudioPreviewBlockRenderer(
                    $renderer,
                    $this->trust,
                    $this->execution,
                    $this->manifest->owner->identifier(),
                    $this->runtimeEntry,
                ),
            );
            ++$registered;
        }
        if ($registered === 0) {
            throw new LogicException('A declared Studio renderer lost every canonical block binding.');
        }
        $this->record($kind, $identifier);
    }

    /**
     * Close the phase only when every signed executable requirement was bound exactly once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function complete(): void
    {
        $this->assertOpen();
        $this->manifest->executableBindingRequirements()->assertSatisfied($this->bound);
        $this->closed = true;
    }

    /**
     * Refuse a closed, undeclared, wrong-kind, or repeated binding before registry mutation.
     *
     * @param   ExecutableBindingKind  $kind        Canonical executable surface.
     * @param   string                 $identifier  Attempted signed identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertBindable(ExecutableBindingKind $kind, string $identifier): void
    {
        $this->assertOpen();
        $this->manifest->executableBindingRequirements()->assertDeclared($kind, $identifier);
        if (in_array($identifier, $this->bound[$kind->value] ?? [], true)) {
            throw new InvalidArgumentException('An executable implementation cannot be bound more than once.');
        }
    }

    /**
     * Record one successfully installed executable.
     *
     * @param   ExecutableBindingKind  $kind        Canonical executable surface.
     * @param   string                 $identifier  Bound signed identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function record(ExecutableBindingKind $kind, string $identifier): void
    {
        $this->bound[$kind->value][] = $identifier;
    }

    /**
     * Refuse mutation after exact reconciliation has completed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new LogicException('The executable binding phase has already completed.');
        }
    }

    /**
     * Resolve one App-owned policy definition derived from the canonical SDK graph.
     *
     * @param   list<ContributionDefinition>  $definitions  App policy definitions derived from the SDK graph.
     * @param   string                        $identifier   Required executable identifier.
     * @param   string                        $kind         Diagnostic contribution kind.
     *
     * @return  ContributionDefinition  Exact matching host definition.
     *
     * @since   2.0.0
     */
    private function definition(array $definitions, string $identifier, string $kind): ContributionDefinition
    {
        foreach ($definitions as $definition) {
            if ($definition->identifier() === $identifier) {
                return $definition;
            }
        }

        throw new LogicException(sprintf('A declared %s binding lost its host policy definition.', $kind));
    }
}
