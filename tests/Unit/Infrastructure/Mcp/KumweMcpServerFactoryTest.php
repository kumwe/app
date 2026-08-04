<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Mcp;

use Kumwe\CMS\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpServerFactory;
use Kumwe\CMS\Infrastructure\Mcp\McpCapabilityCatalog;
use Mcp\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KumweMcpServerFactory::class)]
final class KumweMcpServerFactoryTest extends TestCase
{
    public function testBuildsAnOfficialSdkServerUsingManualRegistration(): void
    {
        $catalog = new McpCapabilityCatalog();
        $server = (new KumweMcpServerFactory($catalog))->create(new KumweMcpHandlers($catalog));

        self::assertInstanceOf(Server::class, $server);
    }
}
