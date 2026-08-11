<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Twig;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Verifies strict Twig scoping across the nested KIS schema-plan workspace components.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class BusinessSchemaPlansTemplateTest extends TestCase
{
    /**
     * Render a selected plan whose operation comparison is evaluated from a nested embed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSelectedPlanRendersOperationStatesWithStrictVariables(): void
    {
        $root = dirname(__DIR__, 4);
        $loader = new FilesystemLoader($root . '/templates/administrator');
        $loader->addPath($root . '/templates/interface-standard', 'kis');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $plan = [
            'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb501',
            'definition_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb401',
            'from_definition_version' => null,
            'to_definition_version' => 1,
            'from_definition_checksum' => null,
            'to_definition_checksum' => str_repeat('a', 64),
            'from_schema_checksum' => null,
            'target_schema_checksum' => str_repeat('b', 64),
            'plan_checksum' => str_repeat('c', 64),
            'status' => 'planned',
            'risk' => 'low',
            'updated_at' => '2026-08-12T00:00:00+00:00',
            'approval' => null,
            'outcome' => null,
            'requires_high_impact' => false,
            'requires_recovery_evidence' => false,
            'operations' => [[
                'ordinal' => 1,
                'kind' => 'add_column',
                'requires_backfill' => false,
                'table' => 'inventory_item',
                'subject' => 'sku',
                'before' => null,
                'after' => [
                    'doctrine_type' => 'string',
                    'nullable' => false,
                    'options' => [],
                ],
                'risk' => 'low',
                'recovery_implication' => 'drop_added_column',
            ]],
        ];
        $tabs = array_map(
            static fn (string $tab): array => [
                'id' => $tab,
                'label' => ucfirst($tab),
                'href' => '/administrator/business-schema-plans?tab=' . $tab,
            ],
            ['summary', 'operations', 'approval', 'execution', 'recovery', 'history'],
        );

        $html = $twig->render('business-schema-plans.twig', [
            'csrf' => 'test-csrf-token',
            'administrator_assets' => ['stylesheets' => [], 'modules' => []],
            'administrator_workspaces' => [],
            'administrator_navigation' => [],
            'administrator_commands_json' => '[]',
            'active_navigation' => 'core.business-schema-plans',
            'capabilities' => [],
            'plans' => [$plan],
            'plan' => $plan,
            'steps' => [],
            'installation' => null,
            'evidence' => null,
            'evidence_qualifies' => false,
            'schema_environment' => [
                'database_driver' => 'pgsql',
                'database_server_version' => '17',
                'application_release' => '2.0.0',
            ],
            'definitions' => [],
            'notice' => null,
            'active_tab' => 'operations',
            'workspace_tabs' => $tabs,
        ]);

        self::assertSame(6, substr_count($html, 'data-kis-tab-panel='));
        self::assertStringContainsString('<strong>Before</strong>', $html);
        self::assertStringContainsString('<span>Absent</span>', $html);
        self::assertStringContainsString('<strong>After</strong>', $html);
        self::assertStringContainsString('<code>string</code> · required', $html);
    }
}
