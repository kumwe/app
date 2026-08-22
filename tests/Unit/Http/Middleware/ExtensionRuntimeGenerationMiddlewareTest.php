<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Http\Middleware;

use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Http\Middleware\ExtensionRuntimeGenerationMiddleware;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(ExtensionRuntimeGenerationMiddleware::class)]
/**
 * Pins request-wide fail-closed behavior for resident extension generations.
 *
 * @since  2.0.0
 */
final class ExtensionRuntimeGenerationMiddlewareTest extends TestCase
{
    /**
     * A stale resident publication returns retryable 503 before any HTTP or MCP route can dispatch.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStaleTrustedRuntimeDrainsBeforeDispatch(): void
    {
        $execution = $this->createStub(ExtensionExecutionGate::class);
        $execution->method('isCurrent')->willReturn(false);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $middleware = new ExtensionRuntimeGenerationMiddleware(
            $execution,
            new RuntimeMaterializationState(
                'test-replica',
                17,
                str_repeat('a', 64),
                str_repeat('b', 64),
                true,
            ),
        );

        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('POST', 'https://kumwe.test/mcp'),
            $handler,
        );

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('1', $response->getHeaderLine('Retry-After'));
        self::assertStringContainsString('runtime changed', (string) $response->getBody());
    }

    /**
     * Current and core-only processes continue through the request pipeline.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCurrentOrCoreOnlyRuntimeContinues(): void
    {
        foreach (
            [
                [true, true],
                [false, false],
            ] as [$trusted, $current]
        ) {
            $execution = $this->createMock(ExtensionExecutionGate::class);
            $expectation = $trusted ? self::once() : self::never();
            $execution->expects($expectation)->method('isCurrent')->willReturn($current);
            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->expects(self::once())->method('handle')->willReturn(new JsonResponse(['ok' => true]));
            $middleware = new ExtensionRuntimeGenerationMiddleware(
                $execution,
                new RuntimeMaterializationState(
                    'test-replica',
                    $trusted ? 17 : -1,
                    $trusted ? str_repeat('a', 64) : '',
                    $trusted ? str_repeat('b', 64) : '',
                    $trusted,
                ),
            );

            self::assertSame(200, $middleware->process(
                (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/'),
                $handler,
            )->getStatusCode());
        }
    }
}
