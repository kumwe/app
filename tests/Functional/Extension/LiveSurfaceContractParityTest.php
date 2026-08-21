<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\Extension;

use Joomla\DI\Container;
use Kumwe\App\Administrator\Http\Middleware\AdministratorAuthorizationMiddleware;
use Kumwe\App\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\App\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\App\Application\Automation\JobExecutionScope;
use Kumwe\App\Application\Automation\JobHandlerRegistry;
use Kumwe\App\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Application\PayloadSchemaValidator;
use Kumwe\App\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\App\BusinessIntegration\Domain\JobContributionDefinition;
use Kumwe\App\BusinessIntegration\Domain\QueueContributionDefinition;
use Kumwe\App\BusinessIntegration\Domain\ScheduleContributionDefinition;
use Kumwe\App\Extension\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Identity\Domain\Capability;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Portal\Contribution\PortalNavigationRegistry;
use Kumwe\App\Portal\Http\Middleware\PortalAuthorizationMiddleware;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Laminas\Diactoros\ServerRequestFactory;
use Mezzio\Application;
use Mezzio\Router\Route;
use Mezzio\Router\RouterInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Proves the production composition graph executes the declarations it publishes.
 *
 * Unlike source-string wiring assertions, every comparison below begins with services resolved from
 * the real container and routes registered in the real router. The contribution registries remain the
 * declaration authority; the event, worker, queue, scheduler, navigation and authorization services
 * are the independently resolved runtime side of each comparison.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class LiveSurfaceContractParityTest extends TestCase
{
    /**
     * Production service graph shared by the three live-parity comparisons in this class.
     *
     * @var    ?Container
     * @since  2.0.0
     */
    private static ?Container $container = null;

    /**
     * Prove production consumers receive the exact canonical registry objects populated at boot.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProductionContainerSharesOneContributionAndAuthorizationGraph(): void
    {
        $container = self::container();
        $contributions = self::service($container, ExtensionContributionRegistrySet::class);

        self::assertSame(
            $contributions->navigation(),
            self::service($container, AdministratorNavigationRegistry::class),
        );
        self::assertSame(
            $contributions->portalNavigation(),
            self::service($container, PortalNavigationRegistry::class),
        );
        self::assertSame(
            $contributions->fieldTypes(),
            self::service($container, FieldTypeRegistry::class),
        );
        self::assertSame(
            $contributions->authorizationPolicies(),
            self::service($container, AuthorizationPolicyRegistry::class),
        );
    }

    /**
     * Compare every live interactive route with authorization and its declared navigation landing page.
     *
     * This walks the complete router collection rather than a hand-picked path list. Every administrator
     * and portal action except the two login endpoints must name live enforceable capability metadata.
     * Every core navigation item must resolve to a real GET route, require its navigation capability and
     * bind to a KIS declaration that names the same capability.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInteractiveRoutesNavigationAndKisMetadataHaveLiveParity(): void
    {
        $container = self::container();
        $contributions = self::service($container, ExtensionContributionRegistrySet::class);
        $authorization = self::service($container, AuthorizationPolicyRegistry::class);
        $routes = $this->routes($container);

        foreach ($routes as $route) {
            $path = $route->getPath();
            $option = null;
            if (str_starts_with($path, '/administrator') && $path !== '/administrator/login') {
                $option = AdministratorAuthorizationMiddleware::OPTION_REQUIRED_CAPABILITIES;
            } elseif (($path === '/portal' || str_starts_with($path, '/portal/')) && $path !== '/portal/login') {
                $option = PortalAuthorizationMiddleware::OPTION_REQUIRED_CAPABILITIES;
            }
            if ($option === null) {
                continue;
            }

            $capabilities = $route->getOptions()[$option] ?? null;
            self::assertIsArray($capabilities, $route->getName() . ' has no live authorization metadata.');
            self::assertNotSame([], $capabilities, $route->getName() . ' has empty authorization metadata.');
            self::assertTrue(array_is_list($capabilities), $route->getName() . ' capability metadata is not a list.');
            foreach ($capabilities as $capability) {
                self::assertIsString($capability, $route->getName() . ' has non-string capability metadata.');
                $definition = $authorization->capability(Capability::fromString($capability));
                self::assertNotNull($definition, $route->getName() . ' names an unregistered capability.');
                self::assertTrue($definition->enforceable(), $route->getName() . ' names an inactive capability.');
            }
        }

        $surfaceByIdentifier = [];
        foreach ($contributions->interfaceSurfaces()->ownedBy(ContributionOwner::core()) as $surface) {
            self::assertIsString($surface['surface'] ?? null);
            $surfaceByIdentifier[$surface['surface']] = $surface;
        }
        $navigation = [
            ...$contributions->navigation()->ownedBy(ContributionOwner::core()),
            ...$contributions->portalNavigation()->ownedBy(ContributionOwner::core()),
        ];
        self::assertNotSame([], $navigation);
        foreach ($navigation as $item) {
            $identifier = $item['id'] ?? null;
            $href = $item['href'] ?? null;
            $capability = $item['capability'] ?? null;
            $surface = $item['surface'] ?? null;
            self::assertIsString($identifier);
            self::assertIsString($href);
            self::assertIsString($capability);
            self::assertIsString($surface, $identifier . ' has no KIS binding.');
            self::assertArrayHasKey($surface, $surfaceByIdentifier, $surface . ' is not registered live.');
            $surfaceCapabilities = $surfaceByIdentifier[$surface]['capabilities'] ?? null;
            self::assertIsArray($surfaceCapabilities, $surface . ' has invalid capability metadata.');
            self::assertContains(
                $capability,
                $surfaceCapabilities,
                $surface . ' does not declare the navigation capability.',
            );

            $route = $this->matchingGetRoute($container, $href);
            $option = str_starts_with($href, '/administrator')
                ? AdministratorAuthorizationMiddleware::OPTION_REQUIRED_CAPABILITIES
                : PortalAuthorizationMiddleware::OPTION_REQUIRED_CAPABILITIES;
            $routeCapabilities = $route->getOptions()[$option] ?? null;
            self::assertIsArray($routeCapabilities, $identifier . ' landing route has invalid authorization.');
            self::assertContains(
                $capability,
                $routeCapabilities,
                $identifier . ' differs from its landing route authorization.',
            );
        }
    }

    /**
     * Compare signed integration declarations with independently resolved runtime registries.
     *
     * Core currently publishes an event contract while installed extensions may add consumers, jobs,
     * queues and schedules. The assertions deliberately accept an empty contributed surface, but become
     * exhaustive automatically when a trusted runtime generation contributes entries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEventWorkerQueueAndScheduleDeclarationsMatchLiveRuntimeServices(): void
    {
        $container = self::container();
        $contributions = self::service($container, ExtensionContributionRegistrySet::class);
        $events = self::service($container, EventContractRegistry::class);
        $handlers = self::service($container, JobHandlerRegistry::class);
        $scopes = self::service($container, JobExecutionScope::class);
        $queuePolicies = self::service($container, QueueRuntimePolicyCatalog::class);
        $payloads = new PayloadSchemaValidator();

        foreach ($contributions->eventSchemas()->definitions() as $schema) {
            self::assertInstanceOf(EventSchemaDefinition::class, $schema);
            self::assertSame(
                $schema->toArray(),
                $events->schema($schema->eventType(), $schema->schemaVersion())->toArray(),
                $schema->identifier() . ' differs from the live event registry.',
            );
        }
        foreach ($contributions->eventConsumers()->definitions() as $consumer) {
            self::assertInstanceOf(EventConsumerDefinition::class, $consumer);
            self::assertSame(
                $consumer->toArray(),
                $events->consumer($consumer->identifier())->toArray(),
                $consumer->identifier() . ' differs from the live consumer registry.',
            );
        }

        $jobs = [];
        foreach ($contributions->jobs()->definitions() as $job) {
            self::assertInstanceOf(JobContributionDefinition::class, $job);
            $jobs[$job->identifier()] = $job;
            $handler = $handlers->find($job->identifier());
            self::assertNotNull($handler, $job->identifier() . ' has no live worker handler.');
            self::assertSame($job->identifier(), $handler->type());
            self::assertSame($job->installationWide(), $scopes->isInstallationGlobal($job->identifier()));
        }

        $policies = [];
        foreach ($queuePolicies->policies() as $policy) {
            $policies[$policy->queue] = $policy;
        }
        foreach ($contributions->queues()->definitions() as $queue) {
            self::assertInstanceOf(QueueContributionDefinition::class, $queue);
            self::assertArrayHasKey($queue->identifier(), $policies);
            $policy = $policies[$queue->identifier()];
            self::assertSame($queue->leaseSeconds(), $policy->leaseSeconds);
            self::assertSame($queue->maximumAttempts(), $policy->maximumAttempts);
            self::assertSame($queue->maximumInFlight(), $policy->maximumInFlight);
            self::assertSame($queue->retentionDays(), $policy->retentionDays);
        }
        self::assertCount(count($contributions->queues()->definitions()), $policies);

        foreach ($contributions->schedules()->definitions() as $schedule) {
            self::assertInstanceOf(ScheduleContributionDefinition::class, $schedule);
            self::assertArrayHasKey($schedule->jobType(), $jobs, $schedule->identifier() . ' has no live job.');
            $job = $jobs[$schedule->jobType()];
            self::assertNotNull($handlers->find($schedule->jobType()));
            self::assertSame($job->installationWide(), $schedule->siteIdentifier() === null);
            $payloads->assertPayload($job->payloadSchema(), $schedule->payload());
            if ($schedule->queue() !== 'default') {
                self::assertArrayHasKey(
                    $schedule->queue(),
                    $policies,
                    $schedule->identifier() . ' has no live queue policy.',
                );
            }
        }
    }

    /**
     * Build the production container only once for this contract suite.
     *
     * @return  Container  Fully composed production service graph.
     *
     * @since   2.0.0
     */
    private static function container(): Container
    {
        if (!self::$container instanceof Container) {
            self::$container = (new ContainerFactory())->create(Environment::fromGlobals());
            self::assertInstanceOf(Application::class, self::$container->get(Application::class));
        }

        return self::$container;
    }

    /**
     * Resolve and type-check one production service.
     *
     * @template T of object
     *
     * @param   Container        $container  Production container.
     * @param   class-string<T>  $type       Required service contract.
     *
     * @return  T  Resolved service.
     *
     * @since   2.0.0
     */
    private static function service(Container $container, string $type): object
    {
        $service = $container->get($type);
        self::assertInstanceOf($type, $service);

        return $service;
    }

    /**
     * Return every route registered in the live router.
     *
     * @param   Container  $container  Production container.
     *
     * @return  list<Route>  Complete route collection in registration order.
     *
     * @since   2.0.0
     */
    private function routes(Container $container): array
    {
        $router = self::service($container, RouterInterface::class);
        $router->match((new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/__routes__'));
        $property = new ReflectionProperty($router, 'routes');
        $routes = $property->getValue($router);
        self::assertIsArray($routes);
        foreach ($routes as $route) {
            self::assertInstanceOf(Route::class, $route);
        }

        /** @var list<Route> $routes */
        return array_values($routes);
    }

    /**
     * Match a declared navigation destination through the production router.
     *
     * @param   Container  $container  Production container.
     * @param   string     $path       Core navigation href.
     *
     * @return  Route  Successful live GET route.
     *
     * @since   2.0.0
     */
    private function matchingGetRoute(Container $container, string $path): Route
    {
        $router = self::service($container, RouterInterface::class);
        $result = $router->match((new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://kumwe.test' . $path,
        ));
        self::assertTrue($result->isSuccess(), $path . ' is declared in navigation but has no live GET route.');
        $route = $result->getMatchedRoute();
        self::assertInstanceOf(Route::class, $route);

        return $route;
    }
}
