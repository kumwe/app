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
        self::assertContains('kumwe_menu_item_get', $summary['tools']);
        self::assertContains('kumwe_menu_item_update', $summary['tools']);
        self::assertContains('kumwe_menu_item_delete', $summary['tools']);
        self::assertContains('kumwe_token_rotate', $summary['tools']);
        self::assertContains('kumwe_token_emergency_revoke_subject', $summary['tools']);
        self::assertContains('kumwe_token_revoke_subject_site', $summary['tools']);
        self::assertContains('kumwe_trust_key_add', $summary['tools']);
        self::assertContains('kumwe_trust_key_rotate', $summary['tools']);
        self::assertContains('kumwe_trust_key_revoke', $summary['tools']);
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

    public function testMenuItemMutationsPublishTypedTargets(): void
    {
        $tools = (new McpCapabilityCatalog())->tools();
        foreach (['kumwe_menu_item_create', 'kumwe_menu_item_update'] as $name) {
            $tool = array_values(array_filter(
                $tools,
                static fn (array $candidate): bool => $candidate['name'] === $name,
            ))[0] ?? null;
            self::assertIsArray($tool);
            self::assertSame(
                ['content', 'anchor', 'url'],
                $tool['inputSchema']['properties']['targetType']['enum'],
            );
            self::assertArrayHasKey('contentId', $tool['inputSchema']['properties']);
            self::assertArrayHasKey('targetUrl', $tool['inputSchema']['properties']);
        }
    }

    public function testSettingsUseAStableHomepageContentIdentifier(): void
    {
        $tool = array_values(array_filter(
            (new McpCapabilityCatalog())->tools(),
            static fn (array $candidate): bool => $candidate['name'] === 'kumwe_settings_update',
        ))[0] ?? null;

        self::assertIsArray($tool);
        self::assertArrayHasKey('homepageContentId', $tool['inputSchema']['properties']);
        self::assertContains('homepageContentId', $tool['inputSchema']['required']);
        self::assertArrayNotHasKey('homepageSlug', $tool['inputSchema']['properties']);
        self::assertArrayHasKey('presentation', $tool['inputSchema']['properties']);
        self::assertContains('presentation', $tool['inputSchema']['required']);
        self::assertSame(
            ['solid', 'soft', 'outline'],
            $tool['inputSchema']['properties']['presentation']['properties']['button_style']['enum'],
        );
    }

    public function testExtensionActivationPublishesThemeSurfaceSemantics(): void
    {
        $tool = array_values(array_filter(
            (new McpCapabilityCatalog())->tools(),
            static fn (array $candidate): bool => $candidate['name'] === 'kumwe_extension_activate',
        ))[0] ?? null;
        self::assertIsArray($tool);
        self::assertSame(
            ['site', 'administrator', null],
            $tool['inputSchema']['properties']['surface']['enum'],
        );
        self::assertTrue($tool['inputSchema']['properties']['currentPassword']['writeOnly']);
    }

    public function testEveryThemeMutationCanCarryStepUpWithoutPublishingTheSecret(): void
    {
        $tools = (new McpCapabilityCatalog())->tools();
        foreach (['kumwe_extension_activate', 'kumwe_extension_disable', 'kumwe_extension_uninstall'] as $name) {
            $tool = array_values(array_filter(
                $tools,
                static fn (array $candidate): bool => $candidate['name'] === $name,
            ))[0] ?? null;
            self::assertIsArray($tool);
            self::assertTrue($tool['inputSchema']['properties']['currentPassword']['writeOnly']);
        }
    }
}
