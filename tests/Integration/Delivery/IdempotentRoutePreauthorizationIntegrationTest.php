<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Delivery;

use Kumwe\App\Delivery\Http\Api\Idempotency\HttpMutationPreauthorizer;
use Kumwe\App\Delivery\Http\Api\Idempotency\PersistentIdempotencyMiddleware;
use Kumwe\App\Delivery\Http\Api\Idempotency\SecretOnceIdempotencyMiddleware;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\Stratigility\MiddlewarePipe;
use Mezzio\Application;
use Mezzio\Middleware\LazyLoadingMiddleware;
use Mezzio\Router\RouteCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use Throwable;

/**
 * Proves every route behind a persistent idempotency ledger has an exact pre-authorization policy.
 *
 * The ledger middleware consults `HttpMutationPreauthorizer` before it reads or reserves a key, and the
 * pre-authorizer refuses every route shape no branch claims. A route mounted behind the ledger without a policy
 * therefore answers every call with a 500 — which is how the REST business-definition lifecycle, the posting-period
 * moves and every schema-plan stage were unreachable until this parity work. This test walks the live route table,
 * finds each route whose pipeline holds a ledger middleware, and proves the pre-authorizer claims it.
 *
 * @since  2.0.0
 */
#[CoversClass(HttpMutationPreauthorizer::class)]
final class IdempotentRoutePreauthorizationIntegrationTest extends TestCase
{
    /**
     * No ledger-backed route falls through to the pre-authorizer's refuse-by-default branch.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryLedgerBackedRouteHasAnExactPolicy(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $container->get(Application::class);
        $collector = $container->get(RouteCollector::class);
        $middleware = $container->get(PersistentIdempotencyMiddleware::class);
        self::assertInstanceOf(RouteCollector::class, $collector);
        self::assertInstanceOf(PersistentIdempotencyMiddleware::class, $middleware);
        $preauthorizer = (new ReflectionObject($middleware))->getProperty('preauthorization')->getValue($middleware);
        self::assertInstanceOf(HttpMutationPreauthorizer::class, $preauthorizer);
        $context = TestKernelFactory::administratorContext($container);
        $unclaimed = [];
        $checked = 0;
        foreach ($collector->getRoutes() as $route) {
            if (!self::behindLedger($route->getMiddleware())) {
                continue;
            }
            $path = (string) preg_replace(
                ['/\{version\}/', '/\{[^}]+\}/'],
                ['1', '018f22e2-7c8b-7ab0-8f3a-88e8026bb999'],
                $route->getPath(),
            );
            foreach ($route->getAllowedMethods() ?? [] as $method) {
                $checked++;
                try {
                    $preauthorizer->authorize(
                        (new ServerRequestFactory())
                            ->createServerRequest($method, 'https://kumwe.test' . $path)
                            ->withBody((new StreamFactory())->createStream('{}')),
                        $context,
                    );
                } catch (Throwable $refusal) {
                    if ($refusal->getMessage() === 'The idempotent endpoint has no exact authorization policy.') {
                        $unclaimed[] = $method . ' ' . $route->getPath();
                    }
                }
            }
        }

        self::assertGreaterThan(40, $checked);
        self::assertSame([], $unclaimed, 'Ledger-backed routes without a pre-authorization policy.');
    }

    /**
     * Whether a route pipeline holds a persistent or secret-once idempotency ledger.
     *
     * @param   mixed  $middleware  Route middleware.
     *
     * @return  bool  True when a ledger middleware is in the pipeline.
     *
     * @since   2.0.0
     */
    private static function behindLedger(mixed $middleware): bool
    {
        if (!$middleware instanceof MiddlewarePipe) {
            return false;
        }
        $pipeline = (new ReflectionObject($middleware))->getProperty('pipeline')->getValue($middleware);
        self::assertIsIterable($pipeline);
        foreach ($pipeline as $member) {
            if (!$member instanceof LazyLoadingMiddleware) {
                continue;
            }
            $name = (new ReflectionObject($member))->getProperty('middlewareName')->getValue($member);
            $ledgers = [PersistentIdempotencyMiddleware::class, SecretOnceIdempotencyMiddleware::class];
            if (in_array($name, $ledgers, true)) {
                return true;
            }
        }

        return false;
    }
}
