<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Mcp;

use Kumwe\CMS\Infrastructure\Mcp\McpCapabilityCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpCapabilityCatalog::class)]
final class McpCapabilityCatalogTest extends TestCase
{
    public function testCatalogDeclaresCapabilityProtectedIdempotentMutations(): void
    {
        $catalog = new McpCapabilityCatalog();
        $summary = $catalog->publicSummary();

        self::assertSame('capability_protected_read_write', $summary['mode']);
        self::assertContains('kumwe_content_create', $summary['tools']);
        self::assertContains('kumwe_settings_update', $summary['tools']);
        self::assertSame(['kumwe://capabilities'], $summary['resources']);
        self::assertSame(['kumwe_site_review'], $summary['prompts']);

        foreach ($catalog->tools() as $tool) {
            if ($tool['readOnly']) {
                continue;
            }
            self::assertTrue($tool['idempotent']);
            self::assertArrayHasKey('operationId', $tool['inputSchema']['properties']);
        }
    }
}
