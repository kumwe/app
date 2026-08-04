<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Http\Mcp;

use Kumwe\CMS\Delivery\Http\Mcp\McpHttpHandler;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpServerFactory;
use Kumwe\CMS\Infrastructure\Mcp\McpCapabilityCatalog;
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
        $request = (new ServerRequest())
            ->withMethod('OPTIONS')
            ->withUri(new \Laminas\Diactoros\Uri('https://kumwe.test/mcp'));

        self::assertSame(204, $this->handler()->handle($request)->getStatusCode());
    }

    private function handler(int $maxBodyBytes = 1_048_576): McpHttpHandler
    {
        $catalog = new McpCapabilityCatalog();

        return new McpHttpHandler(
            new KumweMcpServerFactory($catalog),
            new KumweMcpHandlers($catalog),
            new ResponseFactory(),
            new StreamFactory(),
            new NullLogger(),
            $maxBodyBytes,
            ['kumwe.test'],
        );
    }
}
