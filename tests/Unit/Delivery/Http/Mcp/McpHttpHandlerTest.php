<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Mcp;

use Kumwe\App\Delivery\Http\Mcp\McpHttpHandler;
use Kumwe\App\Infrastructure\Mcp\KumweMcpServerFactory;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\McpHandlersFixture;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(McpHttpHandler::class)]
final class McpHttpHandlerTest extends TestCase
{
    public function testItRejectsANonPositiveBodyLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->handler(0);
    }

    public function testItRunsTheOfficialTransportForOptionsRequests(): void
    {
        $context = AuthorizationContext::human(['content.read']);
        $request = (new ServerRequest())
            ->withMethod('OPTIONS')
            ->withUri(new \Laminas\Diactoros\Uri('https://kumwe.test/mcp'))
            ->withAttribute(
                AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
                $context->principal(),
            )
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $context);

        self::assertSame(204, $this->handler()->handle($request)->getStatusCode());
    }

    public function testItRejectsMismatchedPrincipalAndExecutionContext(): void
    {
        $context = AuthorizationContext::human(['content.read']);
        $other = AuthorizationContext::human(
            ['content.read'],
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb302',
        );
        $request = (new ServerRequest())
            ->withMethod('OPTIONS')
            ->withUri(new \Laminas\Diactoros\Uri('https://kumwe.test/mcp'))
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $other->principal())
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $context);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('identities must match');

        $this->handler()->handle($request);
    }

    private function handler(int $maxBodyBytes = 1_048_576): McpHttpHandler
    {
        $catalog = new McpCapabilityCatalog();

        return new McpHttpHandler(
            new KumweMcpServerFactory($catalog),
            McpHandlersFixture::create($catalog),
            new ResponseFactory(),
            new StreamFactory(),
            new NullLogger(),
            $maxBodyBytes,
            ['kumwe.test'],
        );
    }
}
