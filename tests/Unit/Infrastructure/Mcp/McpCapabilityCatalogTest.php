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

    public function testBusinessSchemaStagesAreSeparatelyGrantedAndHonestlyAnnotated(): void
    {
        $tools = [];
        foreach ((new McpCapabilityCatalog())->tools() as $tool) {
            $tools[$tool['name']] = $tool;
        }

        // Each stage carries only its own capability, so a token granted inspection cannot
        // approve, and one granted approval cannot execute.
        $expected = [
            'kumwe_business_schema_definitions' => 'business.schema.read',
            'kumwe_business_schema_plan_list' => 'business.schema.read',
            'kumwe_business_schema_plan_get' => 'business.schema.read',
            'kumwe_business_schema_plan_create' => 'business.schema.plan',
            'kumwe_business_schema_plan_approve' => 'business.schema.approve',
            'kumwe_business_schema_plan_execute' => 'business.schema.execute',
            'kumwe_business_schema_plan_recover' => 'business.schema.recover',
        ];
        foreach ($expected as $name => $capability) {
            self::assertArrayHasKey($name, $tools, sprintf('%s is not published.', $name));
            self::assertSame($capability, $tools[$name]['capability'] ?? null);
        }

        // Applying or reconciling physical schema changes tables; a client must be told.
        foreach (['kumwe_business_schema_plan_execute', 'kumwe_business_schema_plan_recover'] as $name) {
            self::assertTrue($tools[$name]['destructive'], sprintf('%s must be marked destructive.', $name));
            self::assertFalse($tools[$name]['readOnly']);
        }
        foreach (['kumwe_business_schema_plan_list', 'kumwe_business_schema_plan_get'] as $name) {
            self::assertTrue($tools[$name]['readOnly']);
            self::assertFalse($tools[$name]['destructive']);
        }
    }

    public function testDestructivePurgePlanningIsNotReachableFromTheAgentSurface(): void
    {
        $names = array_column((new McpCapabilityCatalog())->tools(), 'name');

        // Composing a purge plan requires re-proving a current password, which this surface
        // cannot supply; publishing it would only produce a tool that always fails closed.
        foreach ($names as $name) {
            self::assertStringNotContainsString('purge', $name);
        }
        self::assertNotContains('business.schema.destructive', array_filter(
            array_column((new McpCapabilityCatalog())->tools(), 'capability'),
        ));
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
