<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Mcp;

use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\KumweMcpServerFactory;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Mcp\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(KumweMcpServerFactory::class)]
final class KumweMcpServerFactoryTest extends TestCase
{
    public function testBuildsAnOfficialSdkServerUsingManualRegistration(): void
    {
        $catalog = new McpCapabilityCatalog();
        $handlers = (new ReflectionClass(KumweMcpHandlers::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(KumweMcpHandlers::class, $handlers);
        $server = (new KumweMcpServerFactory($catalog))->create($handlers);

        self::assertInstanceOf(Server::class, $server);
    }
}
