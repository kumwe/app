<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\App\Administrator\Presentation\AdministratorContributionRenderer;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Contribution\AdministratorRouteRegistry;
use Kumwe\App\Extension\Contribution\BusinessContributionSurface;
use Kumwe\App\Extension\Contribution\CanonicalManifestActivator;
use Kumwe\App\Extension\Contribution\CanonicalManifestInterpreter;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\OwnedExtensionBindingRegistrar;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use Kumwe\Extension\Manifest\ManifestContributions;
use Kumwe\Extension\Spi\Application\Automation\JobHandler;
use Kumwe\Extension\Spi\Application\ExecutionContext;
use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteHandlerFactory;
use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteRenderer;
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
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionCommand;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionHandler;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionResult;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewHandler;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewQuery;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewResult;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationInput;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationModel;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresenter;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockRenderer;
use LogicException;
use Mezzio\Application;
use Mezzio\Router\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use RuntimeException;
use Twig\Loader\ArrayLoader;

#[CoversClass(AdministratorRouteRegistry::class)]
#[CoversClass(BusinessContributionSurface::class)]
#[CoversClass(CanonicalManifestActivator::class)]
#[CoversClass(CanonicalManifestInterpreter::class)]
#[CoversClass(ExtensionContributionRegistrySet::class)]
#[CoversClass(OwnedExtensionBindingRegistrar::class)]
#[CoversClass(StudioPreviewRendererContribution::class)]
/**
 * Proves each canonical contribution kind activates and binds behavior only to its signed identifiers.
 *
 * @since  2.0.0
 */
final class ExtensionBindingSurfaceTest extends TestCase
{
    /**
     * Prove every executable integration declaration of a schema-four package binds and reconciles.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalIntegrationDeclarationsBindTheirExecutables(): void
    {
        $manifest = self::generationManifest(4);
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->activateManifest($manifest);

        $registrar->domainListener(
            'kumwe.contract-manifest-four.observe-now',
            self::domainEventHandler(),
        );
        $registrar->eventConsumer(
            'kumwe.contract-manifest-four.observe-later',
            self::integrationEventHandler(),
        );
        $registrar->jobHandler('kumwe.contract-manifest-four.summarize', self::jobHandler());
        $registrar->projection('kumwe.contract-manifest-four.activity', self::projectionBuilder());
        $registrar->webhook(
            'kumwe.contract-manifest-four.observed-webhook',
            self::integrationEventTransport(),
        );
        $registrar->complete();

        $owner = ContributionOwner::extension('kumwe/contract-manifest-four');
        self::assertCount(1, $registries->domainListeners()->ownedBy($owner));
        self::assertCount(1, $registries->eventConsumers()->ownedBy($owner));
        self::assertCount(1, $registries->jobs()->ownedBy($owner));
        self::assertCount(1, $registries->projections()->ownedBy($owner));
        self::assertCount(1, $registries->webhooks()->ownedBy($owner));
        self::assertCount(1, $registries->reports()->ownedBy($owner));
        self::assertCount(1, $registries->queues()->ownedBy($owner));
        self::assertCount(1, $registries->schedules()->ownedBy($owner));
        self::assertCount(1, $registries->eventSchemas()->ownedBy($owner));
    }

    /**
     * Prove field presenters, custom handlers and portal routes bind in any declaration order.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSemanticBusinessAndPortalDeclarationsBindTheirExecutables(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->activateManifest(self::probeManifest());

        $registrar->fieldPresenter('acme.probe.color', self::fieldPresenter());
        $registrar->customBusinessViewHandler('acme.probe.views.second', self::viewHandler());
        $registrar->customBusinessViewHandler('acme.probe.views.first', self::viewHandler());
        $registrar->customBusinessActionHandler('acme.probe.actions.second', self::actionHandler());
        $registrar->customBusinessActionHandler('acme.probe.actions.first', self::actionHandler());
        $registrar->portalRoute('acme.probe.home', self::portalRouteFactory());
        $registrar->complete();

        $inventory = $registries->inventory(ContributionOwner::extension('acme/probe'));
        self::assertIsArray($inventory['business']);
        $business = $inventory['business'];
        self::assertCount(1, $business['field_presentations']);
        self::assertCount(2, $business['view_handlers']);
        self::assertCount(2, $business['action_handlers']);
        self::assertSame('acme.probe.views.first', $business['view_handlers'][0]['handler']);
        self::assertSame('acme.probe.actions.first', $business['action_handlers'][0]['handler']);
        self::assertIsArray($inventory['portal']);
        self::assertCount(1, $inventory['portal']['routes']);
    }

    /**
     * Prove a signed administrator route binds its factory and mounts guarded onto the application.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAdministratorRouteBindsAndMountsThroughItsRegistry(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->activateManifest(self::routesManifest());
        $factory = self::administratorRouteFactory();
        $registrar->administratorRoute('acme.routes.index', $factory);
        $registrar->complete();

        $route = new Route('/administrator/extensions/acme/routes', self::middleware(), ['GET']);
        $application = self::application($route);
        $registries->routes()->registerInto(
            $application,
            self::trustStore(),
            self::administratorRenderer(),
        );

        self::assertCount(1, $application->calls);
        [$path, , $methods, $name] = $application->calls[0];
        self::assertSame('/administrator/extensions/acme/routes', $path);
        self::assertSame(['GET'], $methods);
        self::assertSame('administrator.extension.acme.routes.index', $name);
        self::assertInstanceOf(AdministratorContributionRenderer::class, $factory->renderer);
        self::assertSame(
            ['administrator_required_capabilities' => ['acme.routes.view']],
            $route->getOptions(),
        );
    }

    /**
     * Prove one signed identifier never accepts a second executable implementation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARepeatedExecutableBindingIsRefused(): void
    {
        $registrar = (new ExtensionContributionRegistrySet(withCore: false))
            ->activateManifest(self::routesManifest());
        $registrar->administratorRoute('acme.routes.index', self::administratorRouteFactory());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be bound more than once');

        $registrar->administratorRoute('acme.routes.index', self::administratorRouteFactory());
    }

    /**
     * Prove the registrar refuses every binding once exact reconciliation has completed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testABindingAfterCompletionIsRefused(): void
    {
        $registrar = (new ExtensionContributionRegistrySet(withCore: false))
            ->activateManifest(self::routesManifest());
        $registrar->administratorRoute('acme.routes.index', self::administratorRouteFactory());
        $registrar->complete();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already completed');

        $registrar->administratorRoute('acme.routes.index', self::administratorRouteFactory());
    }

    /**
     * Prove a schema-six package's canonical documents activate and its renderer binds to its block.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACanonicalStudioRendererBindsToItsSignedBlocks(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->activateManifest(
            self::generationManifest(6),
            self::trustStore(),
            self::executionGate(),
            '1.0.0',
            ['identifier' => 'kumwe/contract-manifest-six'],
        );
        $registrar->studioPreviewRenderer(
            'kumwe.contract-manifest-six/grid-preview',
            self::studioPreviewRenderer(),
        );
        $registrar->complete();

        $owner = ContributionOwner::extension('kumwe/contract-manifest-six');
        self::assertCount(6, $registries->canonicalCompositionDocuments()->ownedBy($owner));
        self::assertCount(2, $registries->compositionHostBindings()->ownedBy($owner));
        $renderers = $registries->studioPreviewRenderers()->ownedBy($owner);
        self::assertCount(1, $renderers);
        self::assertSame('kumwe.contract-manifest-six/grid', $renderers[0]['type']);
        self::assertSame('1.0.0', $renderers[0]['runtime_version']);
        self::assertSame('kumwe.contract-manifest-six/grid-preview', $renderers[0]['renderer']);
    }

    /**
     * Prove a declared Studio renderer cannot bind without exact signed runtime provenance.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStudioRendererWithoutRuntimeProvenanceIsRefused(): void
    {
        $registrar = (new ExtensionContributionRegistrySet(withCore: false))
            ->activateManifest(self::generationManifest(6));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires exact signed runtime provenance');

        $registrar->studioPreviewRenderer(
            'kumwe.contract-manifest-six/grid-preview',
            self::studioPreviewRenderer(),
        );
    }

    /**
     * Prove interface surface and translation-group declarations activate into their registries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInterfaceAndContentDeclarationsActivateIntoTheirRegistries(): void
    {
        $announcements = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 4) . '/examples/extensions/announcements/kumwe.json',
            ),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($announcements);
        $surfaces = new ExtensionContributionRegistrySet(withCore: false);
        $surfaces->activateManifest(ManifestContributions::fromManifest(
            ExtensionIdentifier::fromString('kumwe/announcements-example'),
            $announcements['contributions'],
            4,
        ));
        $announcer = ContributionOwner::extension('kumwe/announcements-example');
        self::assertCount(1, $surfaces->interfaceSurfaces()->ownedBy($announcer));

        $content = new ExtensionContributionRegistrySet(withCore: false);
        $content->activateManifest(ManifestContributions::fromManifest(
            ExtensionIdentifier::fromString('acme/probe'),
            [
                'version' => 2,
                'content' => [
                    'translation_groups' => [[
                        'group_id' => 'acme.probe.messages',
                        'locales' => ['en-GB', 'de-DE'],
                        'fallback_locale' => 'en-GB',
                    ]],
                ],
            ],
            4,
        ));
        $groups = $content->contentTranslationGroups()->ownedBy(ContributionOwner::extension('acme/probe'));
        self::assertCount(1, $groups);
        self::assertSame('acme.probe.messages', $groups[0]['group_id']);
    }

    /**
     * Parse one frozen SDK generation fixture under its own schema grammar.
     *
     * @param   int  $generation  Manifest schema generation, matching the fixture directory.
     *
     * @return  ManifestContributions  Canonical signed declaration graph.
     *
     * @since   2.0.0
     */
    private static function generationManifest(int $generation): ManifestContributions
    {
        $path = dirname(__DIR__, 4)
            . '/vendor/kumwe/extension-sdk/resources/fixtures/generations/manifest-' . $generation
            . '/kumwe.json';
        $document = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertIsString($document['name']);
        self::assertIsArray($document['contributions']);

        return ManifestContributions::fromManifest(
            ExtensionIdentifier::fromString($document['name']),
            $document['contributions'],
            $generation,
        );
    }

    /**
     * Build the probe manifest declaring a presenter, paired custom handlers, and one portal route.
     *
     * @return  ManifestContributions  Canonical signed declaration graph.
     *
     * @since   2.0.0
     */
    private static function probeManifest(): ManifestContributions
    {
        $schema = static fn (string $marker): array => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [$marker => ['type' => 'string']],
        ];

        return ManifestContributions::fromManifest(ExtensionIdentifier::fromString('acme/probe'), [
            'version' => 1,
            'capabilities' => [[
                'id' => 'acme.probe.use',
                'label' => 'Use probe',
                'description' => 'Use the probe portal surfaces.',
            ]],
            'resource_policies' => [[
                'id' => 'acme.probe.portal-access',
                'capability' => 'acme.probe.use',
                'resources' => [['type' => 'portal_session', 'identifiers' => []]],
            ]],
            'portal' => [
                'templates' => [['name' => 'acme.probe.home', 'template' => 'home.twig']],
                'routes' => [[
                    'name' => 'acme.probe.home',
                    'path' => '/',
                    'methods' => ['GET'],
                    'capability' => 'acme.probe.use',
                    'template' => 'acme.probe.home',
                ]],
            ],
            'business' => [
                'field_types' => [[
                    'id' => 'acme.probe.color',
                    'label' => 'Color',
                    'description' => 'A named color value.',
                    'value_type' => 'string',
                    'storage_type' => 'string',
                ]],
                'field_presentations' => [[
                    'field_type' => 'acme.probe.color',
                    'contexts' => ['list', 'detail'],
                ]],
                'view_handlers' => [
                    [
                        'handler' => 'acme.probe.views.first',
                        'schema' => 'acme.probe.schemas.first-view-v1',
                        'query_schema' => $schema('q1'),
                        'result_schema' => $schema('r1'),
                    ],
                    [
                        'handler' => 'acme.probe.views.second',
                        'schema' => 'acme.probe.schemas.second-view-v1',
                        'query_schema' => $schema('q2'),
                        'result_schema' => $schema('r2'),
                    ],
                ],
                'action_handlers' => [
                    [
                        'handler' => 'acme.probe.actions.first',
                        'schema' => 'acme.probe.schemas.first-action-v1',
                        'command_schema' => $schema('c1'),
                        'result_schema' => $schema('r3'),
                    ],
                    [
                        'handler' => 'acme.probe.actions.second',
                        'schema' => 'acme.probe.schemas.second-action-v1',
                        'command_schema' => $schema('c2'),
                        'result_schema' => $schema('r4'),
                    ],
                ],
            ],
        ], 3);
    }

    /**
     * Build the manifest declaring exactly one guarded administrator route with its view.
     *
     * @return  ManifestContributions  Canonical signed declaration graph.
     *
     * @since   2.0.0
     */
    private static function routesManifest(): ManifestContributions
    {
        return ManifestContributions::fromManifest(ExtensionIdentifier::fromString('acme/routes'), [
            'version' => 1,
            'capabilities' => [[
                'id' => 'acme.routes.view',
                'label' => 'View routes',
                'description' => 'Open the exact extension route.',
            ]],
            'administrator' => [
                'views' => [['name' => 'acme.routes.index', 'template' => 'index.twig']],
                'routes' => [[
                    'name' => 'acme.routes.index',
                    'path' => '/',
                    'methods' => ['GET'],
                    'capability' => 'acme.routes.view',
                    'view' => 'acme.routes.index',
                ]],
            ],
        ], 2);
    }

    /**
     * Build an inert domain-event executable for a declared listener.
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
     * Build an inert durable-consumer executable for a declared consumer.
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
     * Build an inert job executable for a declared job type.
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
     * Build an inert projection executable for a declared projection.
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
     * Build an inert outbound transport for a declared webhook adapter.
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
     * Build a fixed field presenter for the declared probe field type.
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
     * Build a fixed custom business view executable.
     *
     * @return  CustomBusinessViewHandler  Handler serving one empty document.
     *
     * @since   2.0.0
     */
    private static function viewHandler(): CustomBusinessViewHandler
    {
        return new class implements CustomBusinessViewHandler {
            /**
             * Serve one fixed probe document for a declared custom view contract.
             *
             * @param   CustomBusinessViewQuery  $query  Host-validated view query.
             *
             * @return  CustomBusinessViewResult  Fixed probe view document.
             *
             * @since   2.0.0
             */
            public function handle(CustomBusinessViewQuery $query): CustomBusinessViewResult
            {
                unset($query);

                return new CustomBusinessViewResult([]);
            }
        };
    }

    /**
     * Build a fixed custom business action executable.
     *
     * @return  CustomBusinessActionHandler  Handler serving one empty outcome.
     *
     * @since   2.0.0
     */
    private static function actionHandler(): CustomBusinessActionHandler
    {
        return new class implements CustomBusinessActionHandler {
            /**
             * Accept one fixed probe command for a declared custom action contract.
             *
             * @param   CustomBusinessActionCommand  $command  Host-validated action command.
             *
             * @return  CustomBusinessActionResult  Fixed probe action outcome.
             *
             * @since   2.0.0
             */
            public function handle(CustomBusinessActionCommand $command): CustomBusinessActionResult
            {
                unset($command);

                return new CustomBusinessActionResult([]);
            }
        };
    }

    /**
     * Build a portal route handler factory returning one inert handler.
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

                return ExtensionBindingSurfaceTest::inertHandler();
            }
        };
    }

    /**
     * Build an administrator route handler factory that records the renderer it is given.
     *
     * @return  AdministratorRouteHandlerFactory&object{renderer: ?AdministratorRouteRenderer}  Factory probe.
     *
     * @since   2.0.0
     */
    private static function administratorRouteFactory(): AdministratorRouteHandlerFactory
    {
        return new class implements AdministratorRouteHandlerFactory {
            /**
             * Renderer captured from the mount phase, when it has run.
             *
             * @var    ?AdministratorRouteRenderer
             * @since  2.0.0
             */
            public ?AdministratorRouteRenderer $renderer = null;

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
                $this->renderer = $renderer;

                return ExtensionBindingSurfaceTest::inertHandler();
            }
        };
    }

    /**
     * Build a request handler that refuses to serve, for factories under test.
     *
     * @return  RequestHandlerInterface  Handler that always raises.
     *
     * @since   2.0.0
     */
    public static function inertHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            /**
             * Refuse to serve; binding tests never dispatch requests.
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
     * Build a middleware that refuses to process, for the fixed mounted route.
     *
     * @return  MiddlewareInterface  Middleware that always raises.
     *
     * @since   2.0.0
     */
    private static function middleware(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            /**
             * Refuse to process; binding tests never dispatch requests.
             *
             * @param   ServerRequestInterface   $request  Incoming request.
             * @param   RequestHandlerInterface  $handler  Next handler.
             *
             * @return  ResponseInterface  Never returned.
             *
             * @since   2.0.0
             */
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                unset($request, $handler);

                throw new RuntimeException('The inert probe middleware never serves.');
            }
        };
    }

    /**
     * Build an application double that records mounts and serves one fixed route.
     *
     * @param   Route  $route  Fixed route returned for every mount.
     *
     * @return  Application&object{calls: list<array{string, mixed, ?array<int, string>, ?string}>}  Recorder.
     *
     * @since   2.0.0
     */
    private static function application(Route $route): Application
    {
        return new class ($route) extends Application {
            /**
             * Every mount recorded as path, middleware, methods, and name.
             *
             * @var    list<array{string, mixed, ?array<int, string>, ?string}>
             * @since  2.0.0
             */
            public array $calls = [];

            /**
             * Bind the double to the one fixed route it hands back.
             *
             * @param  Route  $fixed  Route returned for every mount.
             *
             * @since  2.0.0
             */
            public function __construct(private readonly Route $fixed)
            {
            }

            /**
             * Record one mounted route instead of routing it.
             *
             * @param   string                  $path        Mounted path.
             * @param   mixed                   $middleware  Pipeline the registry supplied.
             * @param   ?array<int, string>     $methods     Declared HTTP methods.
             * @param   ?string                 $name        Composed route name.
             *
             * @return  Route  The one fixed route.
             *
             * @since   2.0.0
             */
            public function route(string $path, $middleware, ?array $methods = null, ?string $name = null): Route
            {
                $this->calls[] = [$path, $middleware, $methods, $name];

                return $this->fixed;
            }
        };
    }

    /**
     * Build an administrator renderer able to mint extension route renderer capabilities.
     *
     * @return  AdministratorRenderer  Renderer with private host provenance attached.
     *
     * @since   2.0.0
     */
    private static function administratorRenderer(): AdministratorRenderer
    {
        return new AdministratorRenderer(
            new AdministratorTwigEnvironment(new ArrayLoader([])),
            new RecoveryAdministratorRenderer(new RecoveryAdministratorTwigEnvironment(new ArrayLoader([]))),
            extensionRequestProvenance: new \stdClass(),
        );
    }

    /**
     * Materialize a trust boundary value without its persistence collaborators.
     *
     * The binding phase only stores the boundary for later request-time enforcement, so an
     * uninitialized instance is sufficient and keeps the test free of database wiring.
     *
     * @return  TrustStore  Structural trust boundary instance.
     *
     * @since   2.0.0
     */
    private static function trustStore(): TrustStore
    {
        return (new ReflectionClass(TrustStore::class))->newInstanceWithoutConstructor();
    }

    /**
     * Build an always-current execution gate for preview bindings.
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
     * Build an inert Studio preview renderer executable.
     *
     * @return  StudioPreviewBlockRenderer  Renderer that never produces a fragment.
     *
     * @since   2.0.0
     */
    private static function studioPreviewRenderer(): StudioPreviewBlockRenderer
    {
        return new class implements StudioPreviewBlockRenderer {
            /**
             * Refuse to render; binding tests never execute previews.
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
