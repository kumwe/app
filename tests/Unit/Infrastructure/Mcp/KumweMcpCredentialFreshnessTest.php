<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Mcp;

use Kumwe\CMS\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\CMS\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Kumwe\CMS\Tests\Support\McpHandlersFixture;
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
