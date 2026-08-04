<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Mcp;

use Kumwe\CMS\Infrastructure\Mcp\McpCapabilityCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpCapabilityCatalog::class)]
final class McpCapabilityCatalogTest extends TestCase
{
    public function testCatalogContainsOnlyExplicitReadAndPlanCapabilities(): void
    {
        $catalog = new McpCapabilityCatalog();
        $summary = $catalog->publicSummary();

        self::assertSame('read_and_plan_only', $summary['mode']);
        self::assertSame(['kumwe_discover', 'kumwe_plan_review'], $summary['tools']);
        self::assertSame(['kumwe://capabilities'], $summary['resources']);
        self::assertSame(['kumwe_site_review'], $summary['prompts']);

        foreach ($catalog->tools() as $tool) {
            self::assertDoesNotMatchRegularExpression(
                '/install|delete|publish|secret|database|admin/i',
                $tool['name'],
            );
            self::assertSame(false, $tool['outputSchema']['properties']['apply_supported']['const'] ?? false);
        }
    }
}
