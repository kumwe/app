<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\Http;

use JsonException;
use Kumwe\App\Delivery\Http\Api\Concurrency\RequireIfMatchMiddleware;
use Kumwe\App\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\App\Http\Middleware\BearerAuthenticationMiddleware;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
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
 * Compares the complete checked-in REST surface with the routes the production kernel registers.
 *
 * @since  2.0.0
 */
#[CoversClass(ContainerFactory::class)]
final class RestMachineContractParityTest extends TestCase
{
    /**
     * Prove full method/path parity and the security middleware metadata carried by every operation.
     *
     * The comparison starts from the live route collection in both directions; it is not a hand-picked route
     * list. The small separate allowlist proves each intentionally non-REST health, asset or recovery route
     * still exists and has not accidentally entered the OpenAPI namespace.
     *
     * @return  void
     *
     * @throws  JsonException  When a checked-in machine contract is malformed.
     *
     * @since   2.0.0
     */
    public function testLiveRestSurfaceMatchesTheCompleteMachineContract(): void
    {
        $root = dirname(__DIR__, 3);
        $document = $this->object($root . '/api/openapi/kumwe-v1.json');
        $operations = $this->operations($document);
        $routes = $this->routes();
        $restRoutes = array_filter(
            $routes,
            static fn (array $entry): bool => $entry['path'] === '/api/v1'
                || str_starts_with($entry['path'], '/api/v1/')
                || str_starts_with($entry['path'], '/health/'),
        );

        self::assertSame(array_keys($operations), array_keys($restRoutes));
        foreach ($operations as $key => $operation) {
            $route = $restRoutes[$key]['route'];
            $options = $route->getOptions();
            $security = $operation['security'] ?? [];
            $bearer = ($options[BearerAuthenticationMiddleware::OPTION_AUTHENTICATION] ?? null) === 'bearer';
            self::assertSame($bearer, $security !== [], $key . ' security drifted.');
            self::assertSame(
                $options[BearerAuthenticationMiddleware::OPTION_REQUIRED_CAPABILITIES] ?? [],
                $operation['x-kumwe-required-capabilities'] ?? null,
                $key . ' capability drifted.',
            );
            if ($bearer) {
                self::assertSame(
                    'kumwe-http',
                    $options[BearerAuthenticationMiddleware::OPTION_TOKEN_AUDIENCE] ?? null,
                    $key . ' token audience drifted.',
                );
                self::assertSame(
                    'api',
                    $options[BearerAuthenticationMiddleware::OPTION_TOKEN_PURPOSE] ?? null,
                    $key . ' token purpose drifted.',
                );
            }

            $middleware = $this->middlewareNames($route->getMiddleware());
            $idempotent = in_array(RequireIdempotencyKeyMiddleware::class, $middleware, true);
            $preconditioned = in_array(RequireIfMatchMiddleware::class, $middleware, true);
            self::assertSame(
                $idempotent ? 'required' : 'none',
                $operation['x-kumwe-idempotency'] ?? null,
                $key . ' idempotency middleware drifted.',
            );
            self::assertSame(
                $preconditioned ? 'if-match' : 'none',
                $operation['x-kumwe-precondition'] ?? null,
                $key . ' precondition middleware drifted.',
            );
            self::assertSame(
                $operation['path'] === '/api/v1' || str_starts_with($operation['path'], '/api/v1/')
                    ? 'v1'
                    : 'health',
                $operation['x-kumwe-api-version'] ?? null,
                $key . ' route version drifted.',
            );
            self::assertSame('1.0.0', $operation['x-kumwe-contract-version'] ?? null);
        }

        $allowlist = $this->object($root . '/api/openapi/route-exclusions.json');
        self::assertSame('kumwe-openapi-route-exclusions-v1', $allowlist['format'] ?? null);
        self::assertIsArray($allowlist['exclusions'] ?? null);
        $categories = [];
        foreach ($allowlist['exclusions'] as $exclusion) {
            self::assertIsArray($exclusion);
            $key = ($exclusion['method'] ?? '') . ' ' . ($exclusion['path'] ?? '');
            self::assertArrayHasKey($key, $routes, $key . ' is allowlisted but not live.');
            self::assertArrayNotHasKey($key, $operations, $key . ' is both contracted and excluded.');
            $categories[] = $exclusion['category'] ?? null;
        }
        sort($categories, SORT_STRING);
        self::assertSame(['asset', 'health', 'recovery', 'recovery', 'recovery'], $categories);
    }

    /**
     * Index every OpenAPI operation by its exact live comparison key.
     *
     * @param   array<string, mixed>  $document  Decoded OpenAPI document.
     *
     * @return  array<string, array<string, mixed>>  Sorted operation declarations carrying their paths.
     *
     * @since   2.0.0
     */
    private function operations(array $document): array
    {
        self::assertIsArray($document['paths'] ?? null);
        $operations = [];
        foreach ($document['paths'] as $path => $pathItem) {
            self::assertIsString($path);
            self::assertIsArray($pathItem);
            foreach (['get', 'put', 'post', 'patch', 'delete', 'head', 'options', 'trace'] as $method) {
                if (!isset($pathItem[$method])) {
                    continue;
                }
                self::assertIsArray($pathItem[$method]);
                $key = strtoupper($method) . ' ' . $path;
                $operations[$key] = [...$pathItem[$method], 'path' => $path];
            }
        }
        ksort($operations, SORT_STRING);

        return $operations;
    }

    /**
     * Read every route from the real FastRoute adapter after the application registers its graph.
     *
     * @return  array<string, array{path: string, route: Route}>  Sorted method/path route map.
     *
     * @since   2.0.0
     */
    private function routes(): array
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        self::assertInstanceOf(Application::class, $container->get(Application::class));
        $router = $container->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        $router->match((new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/__routes__'));
        $property = new ReflectionProperty($router, 'routes');
        $registered = $property->getValue($router);
        self::assertIsArray($registered);
        $routes = [];
        foreach ($registered as $route) {
            self::assertInstanceOf(Route::class, $route);
            $methods = $route->getAllowedMethods();
            self::assertIsArray($methods);
            foreach ($methods as $method) {
                $routes[$method . ' ' . $route->getPath()] = [
                    'path' => $route->getPath(),
                    'route' => $route,
                ];
            }
        }
        ksort($routes, SORT_STRING);

        return $routes;
    }

    /**
     * Flatten one prepared route pipeline into the service names it will resolve.
     *
     * @param   MiddlewareInterface  $middleware  Prepared route middleware or nested pipeline.
     *
     * @return  list<string>  Concrete class and lazy service names in execution order.
     *
     * @since   2.0.0
     */
    private function middlewareNames(MiddlewareInterface $middleware): array
    {
        if ($middleware instanceof LazyLoadingMiddleware) {
            return [$middleware->middlewareName];
        }
        if ($middleware instanceof Traversable) {
            $names = [];
            foreach ($middleware as $nested) {
                self::assertInstanceOf(MiddlewareInterface::class, $nested);
                array_push($names, ...$this->middlewareNames($nested));
            }

            return $names;
        }

        return [$middleware::class];
    }

    /**
     * Decode one checked-in JSON object.
     *
     * @param   string  $path  Absolute machine-contract path.
     *
     * @return  array<string, mixed>  Decoded JSON object.
     *
     * @throws  JsonException  When the document is malformed.
     *
     * @since   2.0.0
     */
    private function object(string $path): array
    {
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        $document = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertFalse(array_is_list($document));

        /** @var array<string, mixed> $document */
        return $document;
    }
}
