<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Contribution;

use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\OwnedExtensionBindingRegistrar;
use Kumwe\Extension\Manifest\ManifestContributions;
use Kumwe\Extension\Spi\Application\Automation\JobHandler;
use Kumwe\Extension\Spi\Application\ExecutionContext;
use Kumwe\Extension\Spi\Binding\Http\PortalRouteHandlerFactory;
use Kumwe\Extension\Spi\Binding\Http\PortalRouteRenderer;
use Kumwe\Extension\Spi\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventTransport;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainEvent;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainListenerDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\JobContributionDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\WebhookContributionDefinition;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionBuilder;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionEvent;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionWriter;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ProjectionDefinition;
use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteHandlerFactory;
use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteRenderer;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationInput;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationModel;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresenter;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionKind;
use Kumwe\Extension\Spi\Contribution\CompositionHostBinding;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockRenderer;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use RuntimeException;

#[CoversClass(OwnedExtensionBindingRegistrar::class)]
/**
 * Proves the registrar refuses a binding whose canonical definition drifted away after declaration.
 *
 * Each case materializes a structurally inconsistent `ManifestContributions` value — declared in the
 * executable inventory, absent from the typed definition index — which the SDK parser can never emit.
 * The registrar must treat that impossible drift as corruption and refuse, not register.
 *
 * @since  2.0.0
 */
final class OwnedBindingCanonicalDriftTest extends TestCase
{
    /**
     * Prove a declared field presenter refuses to bind once its canonical definition is gone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFieldPresenterWithoutItsCanonicalDefinitionIsRefused(): void
    {
        $registrar = self::registrar([
            'business' => ['field_presentations' => [['field_type' => 'acme.probe.color']]],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('field-presentation binding lost its canonical definition');

        $registrar->fieldPresenter('acme.probe.color', self::fieldPresenter());
    }

    /**
     * Prove a declared administrator route refuses to bind once its canonical definition is gone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAdministratorRouteWithoutItsCanonicalDefinitionIsRefused(): void
    {
        $registrar = self::registrar([
            'administrator' => ['routes' => [['name' => 'acme.probe.index']]],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('administrator route lost its canonical definition');

        $registrar->administratorRoute('acme.probe.index', self::administratorRouteFactory());
    }

    /**
     * Prove a declared portal route refuses to bind once its canonical definition is gone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPortalRouteWithoutItsCanonicalDefinitionIsRefused(): void
    {
        $registrar = self::registrar([
            'portal' => ['routes' => [['name' => 'acme.probe.home']]],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('portal route lost its canonical definition');

        $registrar->portalRoute('acme.probe.home', self::portalRouteFactory());
    }

    /**
     * Prove a declared domain listener refuses to bind once its canonical definition is gone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADomainListenerWithoutItsCanonicalDefinitionIsRefused(): void
    {
        $registrar = self::registrar([
            'integration' => ['domain_listeners' => [['listener_id' => 'acme.probe.listen']]],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('domain listener lost its canonical definition');

        $registrar->domainListener('acme.probe.listen', self::domainEventHandler());
    }

    /**
     * Prove a declared event consumer refuses to bind once its canonical definition is gone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEventConsumerWithoutItsCanonicalDefinitionIsRefused(): void
    {
        $registrar = self::registrar([
            'integration' => ['consumers' => [['consumer_id' => 'acme.probe.consume']]],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('event consumer lost its canonical definition');

        $registrar->eventConsumer('acme.probe.consume', self::integrationEventHandler());
    }

    /**
     * Prove a declared job refuses to bind once its canonical definition is gone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAJobWithoutItsCanonicalDefinitionIsRefused(): void
    {
        $registrar = self::registrar(
            ['integration' => ['jobs' => [['job_type' => 'acme.probe.summarize']]]],
            provenance: true,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('job lost its canonical definition');

        $registrar->jobHandler('acme.probe.summarize', self::jobHandler());
    }

    /**
     * Prove a declared projection refuses to bind once its canonical definition is gone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAProjectionWithoutItsCanonicalDefinitionIsRefused(): void
    {
        $registrar = self::registrar([
            'integration' => ['projections' => [['identifier' => 'acme.probe.activity']]],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('projection lost its canonical definition');

        $registrar->projection('acme.probe.activity', self::projectionBuilder());
    }

    /**
     * Prove a declared webhook refuses to bind once its canonical definition is gone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAWebhookWithoutItsCanonicalDefinitionIsRefused(): void
    {
        $registrar = self::registrar([
            'integration' => ['webhooks' => [['adapter_id' => 'acme.probe.outbound']]],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('webhook lost its canonical definition');

        $registrar->webhook('acme.probe.outbound', self::integrationEventTransport());
    }

    /**
     * Prove a Studio renderer binding refuses once its canonical block document is gone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStudioRendererBindingWithoutItsBlockDocumentIsRefused(): void
    {
        $registrar = self::registrar(
            self::studioDeclarations(),
            [
                'compositionHostBindings' => [
                    'block-definition acme.probe/card' => new CompositionHostBinding(
                        CanonicalCompositionKind::BlockDefinition,
                        'acme.probe/card',
                        'acme.probe/card-preview',
                    ),
                ],
            ],
            provenance: true,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('renderer binding lost its canonical block document');

        $registrar->studioPreviewRenderer('acme.probe/card-preview', self::studioPreviewRenderer());
    }

    /**
     * Prove a declared Studio renderer refuses once every canonical block binding is gone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStudioRendererWithoutAnyBlockBindingIsRefused(): void
    {
        $registrar = self::registrar(self::studioDeclarations(), provenance: true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Studio renderer lost every canonical block binding');

        $registrar->studioPreviewRenderer('acme.probe/card-preview', self::studioPreviewRenderer());
    }

    /**
     * Canonical graph declaring one signed Studio block binding and its preview renderer.
     *
     * @return  array<string, mixed>  Composition declarations naming the probe renderer.
     *
     * @since   2.0.0
     */
    private static function studioDeclarations(): array
    {
        return [
            'composition' => [
                'host_bindings' => [[
                    'kind' => 'block-definition',
                    'id' => 'acme.probe/card',
                    'renderer' => 'acme.probe/card-preview',
                ]],
            ],
        ];
    }

    /**
     * Open a binding phase over a deliberately drifted canonical contribution value.
     *
     * The declaration graph feeds the executable inventory while every typed definition index stays
     * empty unless overridden, which is the exact inconsistency the registrar must refuse.
     *
     * @param   array<string, mixed>  $declarations  Raw canonical declaration graph.
     * @param   array<string, mixed>  $overrides     Typed property values overriding the empty default.
     * @param   bool                  $provenance    Whether to attach signed runtime provenance.
     *
     * @return  OwnedExtensionBindingRegistrar  Owner-bound executable sink under test.
     *
     * @since   2.0.0
     */
    private static function registrar(
        array $declarations,
        array $overrides = [],
        bool $provenance = false,
    ): OwnedExtensionBindingRegistrar {
        $manifest = self::driftedManifest($declarations, $overrides);
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        if (!$provenance) {
            return new OwnedExtensionBindingRegistrar($manifest, $registries);
        }

        return new OwnedExtensionBindingRegistrar(
            $manifest,
            $registries,
            (new ReflectionClass(TrustStore::class))->newInstanceWithoutConstructor(),
            self::executionGate(),
            '1.0.0',
            ['identifier' => 'acme/probe'],
        );
    }

    /**
     * Materialize a canonical contribution value whose typed indexes drifted from its declarations.
     *
     * @param   array<string, mixed>  $declarations  Raw canonical declaration graph.
     * @param   array<string, mixed>  $overrides     Typed property values overriding the empty default.
     *
     * @return  ManifestContributions  Structurally inconsistent canonical value.
     *
     * @since   2.0.0
     */
    private static function driftedManifest(array $declarations, array $overrides = []): ManifestContributions
    {
        $reflection = new ReflectionClass(ManifestContributions::class);
        $manifest = $reflection->newInstanceWithoutConstructor();
        foreach ($reflection->getProperties() as $property) {
            $value = match (true) {
                array_key_exists($property->getName(), $overrides) => $overrides[$property->getName()],
                $property->getName() === 'owner' => ContributionOwner::extension('acme/probe'),
                $property->getName() === 'spiVersion' => 2,
                $property->getName() === 'declarations' => $declarations,
                default => [],
            };
            $property->setValue($manifest, $value);
        }

        return $manifest;
    }

    /**
     * Build a fixed field presenter probe.
     *
     * @return  FieldPresenter  Presenter producing one fixed fragment.
     *
     * @since   2.0.0
     */
    private static function fieldPresenter(): FieldPresenter
    {
        return new class implements FieldPresenter {
            /**
             * Present one field value as a fixed probe fragment.
             *
             * @param   FieldPresentationInput  $input  Host-validated presentation input.
             *
             * @return  FieldPresentationModel  Fixed probe presentation model.
             *
             * @since   2.0.0
             */
            public function present(FieldPresentationInput $input): FieldPresentationModel
            {
                unset($input);

                return new FieldPresentationModel('text', ['value' => 'probe']);
            }
        };
    }

    /**
     * Build an administrator route factory probe.
     *
     * @return  AdministratorRouteHandlerFactory  Factory for a handler that never serves.
     *
     * @since   2.0.0
     */
    private static function administratorRouteFactory(): AdministratorRouteHandlerFactory
    {
        return new class implements AdministratorRouteHandlerFactory {
            /**
             * Create the inert handler for a mounted administrator route.
             *
             * @param   AdministratorRouteRenderer  $renderer  Host-issued capability renderer.
             *
             * @return  RequestHandlerInterface  Handler that refuses to serve.
             *
             * @since   2.0.0
             */
            public function create(AdministratorRouteRenderer $renderer): RequestHandlerInterface
            {
                unset($renderer);

                return OwnedBindingCanonicalDriftTest::inertHandler();
            }
        };
    }

    /**
     * Build a portal route factory probe.
     *
     * @return  PortalRouteHandlerFactory  Factory for a handler that never serves.
     *
     * @since   2.0.0
     */
    private static function portalRouteFactory(): PortalRouteHandlerFactory
    {
        return new class implements PortalRouteHandlerFactory {
            /**
             * Create the inert handler for a mounted portal route.
             *
             * @param   PortalRouteRenderer  $renderer  Host-issued portal renderer.
             *
             * @return  RequestHandlerInterface  Handler that refuses to serve.
             *
             * @since   2.0.0
             */
            public function create(PortalRouteRenderer $renderer): RequestHandlerInterface
            {
                unset($renderer);

                return OwnedBindingCanonicalDriftTest::inertHandler();
            }
        };
    }

    /**
     * Build a request handler that refuses to serve.
     *
     * @return  RequestHandlerInterface  Handler that always raises.
     *
     * @since   2.0.0
     */
    public static function inertHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            /**
             * Refuse to serve; drift tests never dispatch requests.
             *
             * @param   ServerRequestInterface  $request  Incoming request.
             *
             * @return  ResponseInterface  Never returned.
             *
             * @since   2.0.0
             */
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                unset($request);

                throw new RuntimeException('The inert probe handler never serves.');
            }
        };
    }

    /**
     * Build an inert domain-event executable probe.
     *
     * @return  DomainEventHandler  Executable that records nothing.
     *
     * @since   2.0.0
     */
    private static function domainEventHandler(): DomainEventHandler
    {
        return new class implements DomainEventHandler {
            /**
             * Accept one synchronous domain event without side effects.
             *
             * @param   DomainListenerDefinition  $definition  Declared listener contract.
             * @param   DomainEvent               $event       Delivered domain event.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function handle(DomainListenerDefinition $definition, DomainEvent $event): void
            {
                unset($definition, $event);
            }
        };
    }

    /**
     * Build an inert durable-consumer executable probe.
     *
     * @return  IntegrationEventHandler  Executable that records nothing.
     *
     * @since   2.0.0
     */
    private static function integrationEventHandler(): IntegrationEventHandler
    {
        return new class implements IntegrationEventHandler {
            /**
             * Accept one durable delivery without side effects.
             *
             * @param   EventConsumerDefinition  $definition  Declared consumer contract.
             * @param   IntegrationEvent         $event       Delivered integration event.
             * @param   ExecutionContext         $context     Host-issued execution capabilities.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function handle(
                EventConsumerDefinition $definition,
                IntegrationEvent $event,
                ExecutionContext $context,
            ): void {
                unset($definition, $event, $context);
            }
        };
    }

    /**
     * Build an inert job executable probe.
     *
     * @return  JobHandler  Executable that records nothing.
     *
     * @since   2.0.0
     */
    private static function jobHandler(): JobHandler
    {
        return new class implements JobHandler {
            /**
             * Accept one job payload without side effects.
             *
             * @param   JobContributionDefinition  $definition  Declared job contract.
             * @param   array<string, mixed>       $payload     Validated job payload.
             * @param   ExecutionContext           $context     Host-issued execution capabilities.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function handle(
                JobContributionDefinition $definition,
                array $payload,
                ExecutionContext $context,
            ): void {
                unset($definition, $payload, $context);
            }
        };
    }

    /**
     * Build an inert projection executable probe.
     *
     * @return  ProjectionBuilder  Executable that writes nothing.
     *
     * @since   2.0.0
     */
    private static function projectionBuilder(): ProjectionBuilder
    {
        return new class implements ProjectionBuilder {
            /**
             * Fold one event into the projection without writing rows.
             *
             * @param   ProjectionDefinition  $definition  Declared projection contract.
             * @param   ProjectionEvent       $event       Business event being folded.
             * @param   ProjectionWriter      $writer      Row writer for derived state.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function apply(
                ProjectionDefinition $definition,
                ProjectionEvent $event,
                ProjectionWriter $writer,
            ): void {
                unset($definition, $event, $writer);
            }
        };
    }

    /**
     * Build an inert outbound transport probe.
     *
     * @return  IntegrationEventTransport  Executable that publishes nothing.
     *
     * @since   2.0.0
     */
    private static function integrationEventTransport(): IntegrationEventTransport
    {
        return new class implements IntegrationEventTransport {
            /**
             * Accept one outbound event without delivering it anywhere.
             *
             * @param   WebhookContributionDefinition  $definition  Declared adapter contract.
             * @param   IntegrationEvent               $event       Event being delivered.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function publish(WebhookContributionDefinition $definition, IntegrationEvent $event): void
            {
                unset($definition, $event);
            }
        };
    }

    /**
     * Build an always-current execution gate probe.
     *
     * @return  ExtensionExecutionGate  Gate that never fences.
     *
     * @since   2.0.0
     */
    private static function executionGate(): ExtensionExecutionGate
    {
        return new class implements ExtensionExecutionGate {
            /**
             * Report the probe runtime generation as current.
             *
             * @return  bool  Always true.
             *
             * @since   2.0.0
             */
            public function isCurrent(): bool
            {
                return true;
            }

            /**
             * Accept execution under the probe runtime generation.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function assertCurrent(): void
            {
            }
        };
    }

    /**
     * Build an inert Studio preview renderer probe.
     *
     * @return  StudioPreviewBlockRenderer  Renderer that never produces a fragment.
     *
     * @since   2.0.0
     */
    private static function studioPreviewRenderer(): StudioPreviewBlockRenderer
    {
        return new class implements StudioPreviewBlockRenderer {
            /**
             * Refuse to render; drift tests never execute previews.
             *
             * @param   StudioPreviewBlock          $block     Validated contributed block.
             * @param   StudioPreviewBindingResult  $binding   Resolved value binding.
             * @param   string                      $viewport  Semantic preview width.
             *
             * @return  StudioPreviewBlockFragment  Never returned.
             *
             * @since   2.0.0
             */
            public function render(
                StudioPreviewBlock $block,
                StudioPreviewBindingResult $binding,
                string $viewport,
            ): StudioPreviewBlockFragment {
                unset($block, $binding, $viewport);

                throw new RuntimeException('The inert probe renderer never renders.');
            }
        };
    }
}
