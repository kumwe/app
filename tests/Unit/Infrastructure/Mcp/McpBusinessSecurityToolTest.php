<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Mcp;

use InvalidArgumentException;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\BuildsBusinessSecurityService;
use Kumwe\App\Tests\Support\McpHandlersFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Proves the MCP Business Security overview is the screen's read model and the catalogue publishes no write.
 *
 * @since  2.0.0
 */
#[CoversClass(KumweMcpHandlers::class)]
final class McpBusinessSecurityToolTest extends TestCase
{
    use BuildsBusinessSecurityService;

    /**
     * The overview answers the service's redacted read model, and no Business Security write is a tool.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheOverviewIsTheScreensReadModelAndNoWriteIsPublished(): void
    {
        $overview = McpHandlersFixture::create(
            new McpCapabilityCatalog(),
            businessSecurity: $this->businessSecurityService(),
        )->forContext(AuthorizationContext::human(['business.security.manage']))->businessSecurityOverview();

        self::assertSame([['identifier' => 'north', 'name' => 'North']], $overview['organizations']);
        self::assertSame([], $overview['tokens']);
        $security = array_values(array_filter(
            array_column((new McpCapabilityCatalog())->tools(), 'capability'),
            static fn (?string $capability): bool => $capability === 'business.security.manage',
        ));
        self::assertSame(['business.security.manage'], $security);
    }

    /**
     * A missing grant and an uncomposed read model are refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusals(): void
    {
        $cases = [
            [InsufficientCapability::class, fn () => McpHandlersFixture::create(
                new McpCapabilityCatalog(),
                businessSecurity: $this->businessSecurityService(),
            )->forContext(AuthorizationContext::human(['content.read']))->businessSecurityOverview()],
            [InvalidArgumentException::class, fn () => McpHandlersFixture::create(new McpCapabilityCatalog())
                ->forContext(AuthorizationContext::human(['business.security.manage']))
                ->businessSecurityOverview()],
        ];
        foreach ($cases as $index => [$expected, $call]) {
            try {
                $call();
                self::fail(sprintf('Refusal %d was not raised.', $index));
            } catch (Throwable $refusal) {
                self::assertInstanceOf($expected, $refusal, (string) $index);
            }
        }
    }
}
