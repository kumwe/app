<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\Http;

use Kumwe\App\Delivery\Console\ConsoleApplication;
use Kumwe\App\Delivery\Console\Contract\CliV2MachineContract;
use Kumwe\App\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\App\Delivery\Http\Api\Studio\StudioAuthoringApiHandler;
use Kumwe\App\Http\Middleware\BearerAuthenticationMiddleware;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Infrastructure\Mcp\McpMutationGuardMode;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Studio\Application\Authoring\HostedContentStudioAuthoringConfigurationProvider;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringOperation;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\Producer\Wire\OperationRegistry;
use Laminas\Diactoros\ServerRequestFactory;
use Mezzio\Application;
use Mezzio\Middleware\LazyLoadingMiddleware;
use Mezzio\Router\Route;
use Mezzio\Router\RouterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\MiddlewareInterface;
use ReflectionProperty;
use Traversable;

/**
 * Keeps every durable browser Studio authoring operation reachable through REST, CLI and MCP.
 *
 * The browser inventory is read from where the browser gets it: the authoring operations the hosted mount
 * advertises and routes to `/administrator/studio/ports/{port}/{operation}`, cross-checked against the
 * pinned Producer registry and the authoring capabilities the host session serves. Each one must have a
 * machine name, a live REST route with an idempotency key exactly when it mutates, a CLI action in the
 * live console contract with the matching effect class and replay key, and an MCP tool whose read-only
 * hint and replay route agree with the registry. A new browser operation without all three machine
 * equivalents fails here instead of silently widening the gap again.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioMachineAuthoringOperation::class)]
#[CoversClass(StudioAuthoringApiHandler::class)]
#[CoversClass(McpCapabilityCatalog::class)]
final class StudioAuthoringMachineParityTest extends TestCase
{
    /**
     * The browser's authoring inventory, the registry and the machine enumeration are one set.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryBrowserAuthoringOperationHasAMachineName(): void
    {
        $browser = self::browserOperations();
        $registry = [];
        foreach (OperationRegistry::all() as $operation) {
            if ($operation->port === 'authoring') {
                $registry[] = $operation->capability;
            }
        }
        sort($registry, SORT_STRING);
        $served = array_values(array_filter(
            StudioHostSessionAuthority::AUTHORING_CAPABILITIES,
            static fn (string $capability): bool => str_starts_with($capability, 'studio.operation/'),
        ));
        sort($served, SORT_STRING);
        $machine = array_map(
            static fn (StudioMachineAuthoringOperation $operation): string => $operation->capability(),
            StudioMachineAuthoringOperation::cases(),
        );
        sort($machine, SORT_STRING);

        self::assertCount(7, $browser);
        self::assertSame($browser, $registry);
        self::assertSame($browser, $served);
        self::assertSame($browser, $machine);
        foreach (StudioMachineAuthoringOperation::cases() as $operation) {
            $registered = OperationRegistry::byCapability($operation->capability());
            self::assertSame($registered->route, $operation->route());
            self::assertSame($registered->mutating, $operation->mutating());
        }
    }

    /**
     * Every operation has a live bearer REST route that requires an idempotency key exactly when it mutates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryOperationHasALiveRestRoute(): void
    {
        $routes = self::routes();
        $session = $routes['POST ' . StudioAuthoringApiHandler::PREFIX . 'sessions'] ?? null;
        self::assertInstanceOf(Route::class, $session);
        foreach (StudioMachineAuthoringOperation::cases() as $operation) {
            $route = $routes['POST ' . StudioAuthoringApiHandler::PREFIX . $operation->value] ?? null;
            self::assertInstanceOf(Route::class, $route, $operation->value . ' has no REST route.');
            $options = $route->getOptions();
            self::assertSame('bearer', $options[BearerAuthenticationMiddleware::OPTION_AUTHENTICATION] ?? null);
            self::assertSame('kumwe-http', $options[BearerAuthenticationMiddleware::OPTION_TOKEN_AUDIENCE] ?? null);
            $middleware = self::middlewareNames($route->getMiddleware());
            self::assertContains(StudioAuthoringApiHandler::class, $middleware);
            self::assertSame(
                $operation->mutating(),
                in_array(RequireIdempotencyKeyMiddleware::class, $middleware, true),
                $operation->value . ' idempotency requirement drifted from the registry.',
            );
        }
    }

    /**
     * Every operation is a CLI action with the matching effect class and replay key in the live contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryOperationHasALiveCliAction(): void
    {
        $contract = CliV2MachineContract::contract();
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $console = $container->get(ConsoleApplication::class);
        self::assertInstanceOf(ConsoleApplication::class, $console);
        self::assertContains('studio-authoring', $console->commandNames());
        self::assertSame('read', $contract->actionRisk('studio-authoring', 'open'));
        foreach (StudioMachineAuthoringOperation::cases() as $operation) {
            self::assertSame(
                $operation->mutating() ? 'mutate' : 'read',
                $contract->actionRisk('studio-authoring', $operation->value),
            );
            $arguments = [
                $operation->value,
                '--site=default',
                '--token-file=/run/token',
                '--session=contexts/key',
                '--session-generation=session-generation',
                '--argument-file=/run/argument.json',
            ];
            if ($operation->mutating()) {
                $contract->validateInvocation('studio-authoring', [...$arguments, '--operation-id=replay-key-0001']);
                try {
                    $contract->validateInvocation('studio-authoring', $arguments);
                    self::fail($operation->value . ' must require --operation-id.');
                } catch (\InvalidArgumentException $missing) {
                    self::assertStringContainsString('operation-id', $missing->getMessage());
                }
            } else {
                self::assertSame($arguments, $contract->validateInvocation('studio-authoring', $arguments));
            }
        }
    }

    /**
     * Every operation is an MCP tool whose hints and replay route agree with the registry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryOperationHasAnMcpTool(): void
    {
        $tools = [];
        foreach ((new McpCapabilityCatalog())->tools() as $tool) {
            $tools[$tool['name']] = $tool;
        }
        self::assertArrayHasKey('kumwe_studio_authoring_open', $tools);
        foreach (StudioMachineAuthoringOperation::cases() as $operation) {
            $name = 'kumwe_studio_authoring_' . str_replace('-', '_', $operation->value);
            self::assertArrayHasKey($name, $tools, $operation->value . ' has no MCP tool.');
            $tool = $tools[$name];
            self::assertSame(!$operation->mutating(), $tool['readOnly']);
            self::assertSame(
                $operation->mutating() ? McpMutationGuardMode::StudioHostBoundary : McpMutationGuardMode::None,
                $tool['mutationGuard'],
            );
            self::assertTrue(method_exists(KumweMcpHandlers::class, $tool['handler']));
        }
    }

    /**
     * The authoring operations the hosted browser mount advertises and routes.
     *
     * @return  list<string>  Sorted qualified operation capabilities.
     *
     * @since   2.0.0
     */
    private static function browserOperations(): array
    {
        $operations = HostedContentStudioAuthoringConfigurationProvider::PORTS['studio.port/authoring'];
        sort($operations, SORT_STRING);

        return $operations;
    }

    /**
     * Index every live route by method and path.
     *
     * @return  array<string, Route>  Routes keyed by `METHOD /path`.
     *
     * @since   2.0.0
     */
    private static function routes(): array
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        self::assertInstanceOf(Application::class, $container->get(Application::class));
        $router = $container->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        $router->match((new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/__routes__'));
        $registered = (new ReflectionProperty($router, 'routes'))->getValue($router);
        self::assertIsArray($registered);
        $routes = [];
        foreach ($registered as $route) {
            self::assertInstanceOf(Route::class, $route);
            $methods = $route->getAllowedMethods();
            self::assertIsArray($methods);
            foreach ($methods as $method) {
                $routes[$method . ' ' . $route->getPath()] = $route;
            }
        }

        return $routes;
    }

    /**
     * Flatten one route's middleware into class names.
     *
     * @param   MiddlewareInterface  $middleware  Route middleware.
     *
     * @return  list<string>  Middleware and handler class names in order.
     *
     * @since   2.0.0
     */
    private static function middlewareNames(MiddlewareInterface $middleware): array
    {
        if ($middleware instanceof LazyLoadingMiddleware) {
            return [$middleware->middlewareName];
        }
        if ($middleware instanceof Traversable) {
            $names = [];
            foreach ($middleware as $nested) {
                self::assertInstanceOf(MiddlewareInterface::class, $nested);
                array_push($names, ...self::middlewareNames($nested));
            }

            return $names;
        }

        return [$middleware::class];
    }
}
