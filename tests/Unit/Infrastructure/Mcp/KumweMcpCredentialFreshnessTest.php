<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Mcp;

use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\McpHandlersFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KumweMcpHandlers::class)]
final class KumweMcpCredentialFreshnessTest extends TestCase
{
    public function testLongLivedHandlerReverifiesTheCredentialForItsSelectedSite(): void
    {
        $tokens = $this->createMock(AccessTokenVerifier::class);
        $tokens->expects(self::exactly(2))
            ->method('verify')
            ->with('retained-secret', 'kumwe-mcp', 'mcp', 'corporate')
            ->willReturnOnConsecutiveCalls(
                AuthorizationContext::principal(['content.read']),
                null,
            );
        $handlers = McpHandlersFixture::create(new McpCapabilityCatalog())->forCredential(
            $tokens,
            'retained-secret',
            'Corporate',
        );

        $this->expectException(InsufficientCapability::class);
        $handlers->listContent();
    }
}
