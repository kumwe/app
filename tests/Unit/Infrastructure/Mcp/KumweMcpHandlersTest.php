<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Mcp;

use InvalidArgumentException;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\McpCapabilityCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KumweMcpHandlers::class)]
final class KumweMcpHandlersTest extends TestCase
{
    public function testBuildsReadOnlyDiscoveryPlanResourceAndPromptResults(): void
    {
        $handlers = new KumweMcpHandlers(new McpCapabilityCatalog());
        $plan = $handlers->planReview('seo.review', 'homepage');
        $resource = json_decode($handlers->capabilityResource(), true, 16, JSON_THROW_ON_ERROR);
        $prompt = $handlers->siteReviewPrompt('seo');

        self::assertSame('read_and_plan_only', $handlers->discover()['mode']);
        self::assertSame('plan_only', $plan['mode']);
        self::assertFalse($plan['apply_supported']);
        self::assertSame('Kumwe CMS', $resource['product']);
        self::assertStringContainsString('do not apply changes', $prompt[0]['content']);
    }

    public function testRejectsMutationOperations(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new KumweMcpHandlers(new McpCapabilityCatalog()))
            ->planReview('extension.install', 'untrusted-package');
    }
}
