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
        self::assertSame(
            ['operationId', 'identifier', 'surface'],
            array_keys($tool['inputSchema']['properties']),
        );
    }

    /**
     * Proves the theme-bearing lifecycle tools publish no step-up property under any spelling.
     *
     * They used to publish `currentPassword`, marked `writeOnly` as though that were a control. Neither
     * the property nor the annotation is a substitute for the credential never being transported at
     * all, so both are asserted absent and the whole input vocabulary is pinned.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoThemeMutationPublishesAStepUpProperty(): void
    {
        $tools = (new McpCapabilityCatalog())->tools();
        foreach (['kumwe_extension_activate', 'kumwe_extension_disable', 'kumwe_extension_uninstall'] as $name) {
            $tool = array_values(array_filter(
                $tools,
                static fn (array $candidate): bool => $candidate['name'] === $name,
            ))[0] ?? null;
            self::assertIsArray($tool);
            $encoded = json_encode($tool, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('currentPassword', $tool['inputSchema']['properties']);
            self::assertStringNotContainsString('currentPassword', $encoded);
            self::assertStringNotContainsString('writeOnly', $encoded);
        }
    }

    /**
     * Proves generated-business MCP tools publish exact capabilities and closed schemas.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedBusinessToolsPublishExactCapabilitiesAndClosedEnvelopes(): void
    {
        $tools = [];
        foreach ((new McpCapabilityCatalog())->tools() as $tool) {
            $tools[$tool['name']] = $tool;
        }

        $expected = [
            'kumwe_business_discover' => ['discoverBusinessRecords', 'business.record.browse'],
            'kumwe_business_inspect' => ['inspectBusinessRecord', 'business.record.read'],
            'kumwe_business_view' => ['executeBusinessView', null],
            'kumwe_business_search' => ['searchBusinessRecords', 'business.record.browse'],
            'kumwe_business_read' => ['readBusinessRecord', 'business.record.read'],
            'kumwe_business_history' => ['businessRecordHistory', 'business.record.history'],
            'kumwe_business_plan_mutation' => ['planBusinessRecordMutation', null],
            'kumwe_business_create' => ['createBusinessRecord', 'business.record.create'],
            'kumwe_business_update' => ['updateBusinessRecord', 'business.record.update'],
            'kumwe_business_archive' => ['archiveBusinessRecord', 'business.record.archive'],
            'kumwe_business_restore' => ['restoreBusinessRecord', 'business.record.restore'],
            'kumwe_business_delete' => ['deleteBusinessRecord', 'business.record.delete'],
            'kumwe_business_relate' => ['relateBusinessRecords', 'business.record.relate'],
            'kumwe_business_unrelate' => ['unrelateBusinessRecords', 'business.record.relate'],
            'kumwe_business_reorder' => ['reorderBusinessRecords', 'business.record.relate'],
            'kumwe_business_request_action' => ['requestBusinessRecordAction', 'business.record.action'],
            'kumwe_business_execute_action' => ['executeBusinessRecordAction', 'business.record.action'],
            'kumwe_business_operation_status' => ['businessRecordOperationStatus', 'business.record.read'],
        ];

        foreach ($expected as $name => [$handler, $capability]) {
            self::assertArrayHasKey($name, $tools, sprintf('%s is not published.', $name));
            self::assertSame($handler, $tools[$name]['handler']);
            self::assertSame($capability, $tools[$name]['capability']);
            self::assertFalse($tools[$name]['inputSchema']['additionalProperties']);
            self::assertFalse($tools[$name]['outputSchema']['additionalProperties']);
        }

        self::assertTrue($tools['kumwe_business_discover']['readOnly']);
        self::assertTrue($tools['kumwe_business_history']['readOnly']);
        self::assertTrue($tools['kumwe_business_plan_mutation']['readOnly']);
        self::assertTrue($tools['kumwe_business_operation_status']['readOnly']);
        self::assertFalse($tools['kumwe_business_create']['readOnly']);
        self::assertTrue($tools['kumwe_business_delete']['destructive']);
        self::assertTrue($tools['kumwe_business_execute_action']['destructive']);
        self::assertSame(
            128,
            $tools['kumwe_business_execute_action']['outputSchema']['properties']['result']['maxProperties'],
        );
    }

    /**
     * Proves every MCP mutation requires a bounded operation identity, plan, and applicable version.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedBusinessMutationsRequireBoundedOperationAndOptimisticVersionIdentities(): void
    {
        $tools = [];
        foreach ((new McpCapabilityCatalog())->tools() as $tool) {
            $tools[$tool['name']] = $tool;
        }
        $mutations = [
            'kumwe_business_create',
            'kumwe_business_update',
            'kumwe_business_archive',
            'kumwe_business_restore',
            'kumwe_business_delete',
            'kumwe_business_relate',
            'kumwe_business_unrelate',
            'kumwe_business_reorder',
            'kumwe_business_request_action',
            'kumwe_business_execute_action',
        ];

        foreach ($mutations as $name) {
            $input = $tools[$name]['inputSchema'];
            self::assertContains('operationId', $input['required']);
            self::assertContains('plan', $input['required']);
            self::assertSame(16, $input['properties']['operationId']['minLength']);
            self::assertSame(128, $input['properties']['operationId']['maxLength']);
            self::assertSame(
                '^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$',
                $input['properties']['operationId']['pattern'],
            );
            self::assertSame(4096, $input['properties']['plan']['maxLength']);
            self::assertSame(
                '^v2\\.[A-Za-z0-9_-]+$',
                $input['properties']['plan']['pattern'],
            );
            self::assertArrayNotHasKey('organization', $input['properties']);
            self::assertArrayNotHasKey('currentPassword', $input['properties']);
        }

        foreach (array_slice($mutations, 1) as $name) {
            self::assertContains('expectedVersion', $tools[$name]['inputSchema']['required']);
        }
        self::assertArrayNotHasKey(
            'expectedVersion',
            $tools['kumwe_business_create']['inputSchema']['properties'],
        );
    }

    /**
     * Proves operation status accepts only an identity and exposes one non-enumerating state.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedBusinessStatusIsNonEnumeratingAndDoesNotSelectAnOperation(): void
    {
        $tools = [];
        foreach ((new McpCapabilityCatalog())->tools() as $tool) {
            $tools[$tool['name']] = $tool;
        }
        $status = $tools['kumwe_business_operation_status'];

        self::assertSame(['operationId'], $status['inputSchema']['required']);
        self::assertSame(['operationId'], array_keys($status['inputSchema']['properties']));
        self::assertSame(['completed'], $status['outputSchema']['properties']['state']['enum']);
        self::assertSame(
            '^business\\.record\\.[a-z_]+$',
            $status['outputSchema']['properties']['operation']['pattern'],
        );
    }

    /**
     * Prove generated history publishes only the shared bounded page and positive version cursor.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedBusinessHistoryIsClosedBoundedAndOmissionSafe(): void
    {
        $history = array_values(array_filter(
            (new McpCapabilityCatalog())->tools(),
            static fn (array $tool): bool => $tool['name'] === 'kumwe_business_history',
        ))[0] ?? null;
        self::assertIsArray($history);

        self::assertSame(['definition', 'record'], $history['inputSchema']['required']);
        self::assertSame(1, $history['inputSchema']['properties']['limit']['minimum']);
        self::assertSame(200, $history['inputSchema']['properties']['limit']['maximum']);
        self::assertSame(1, $history['inputSchema']['properties']['beforeVersion']['minimum']);
        self::assertFalse($history['outputSchema']['additionalProperties']);
        self::assertSame(200, $history['outputSchema']['properties']['items']['maxItems']);
        $revision = $history['outputSchema']['properties']['items']['items'];
        self::assertFalse($revision['additionalProperties']);
        self::assertSame(256, $revision['properties']['changed_fields']['maxItems']);
        self::assertArrayNotHasKey('record_key', $revision['properties']);
        self::assertStringNotContainsString('record_key', json_encode($history, JSON_THROW_ON_ERROR));
    }

    /**
     * Proves the generic MCP surface publishes neither checker votes nor secret step-up input.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedBusinessSurfacePublishesNoCheckerVoteOrSecretInput(): void
    {
        $tools = array_values(array_filter(
            (new McpCapabilityCatalog())->tools(),
            static fn (array $tool): bool => str_starts_with($tool['name'], 'kumwe_business_'),
        ));
        $generated = array_values(array_filter(
            $tools,
            static fn (array $tool): bool => in_array(
                $tool['handler'],
                [
                    'discoverBusinessRecords', 'inspectBusinessRecord', 'searchBusinessRecords',
                    'readBusinessRecord', 'businessRecordHistory', 'planBusinessRecordMutation',
                    'createBusinessRecord',
                    'updateBusinessRecord',
                    'archiveBusinessRecord', 'restoreBusinessRecord', 'deleteBusinessRecord',
                    'relateBusinessRecords', 'unrelateBusinessRecords', 'reorderBusinessRecords',
                    'requestBusinessRecordAction', 'executeBusinessRecordAction',
                    'businessRecordOperationStatus',
                ],
                true,
            ),
        ));
        $encoded = json_encode($generated, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('record_key', $encoded);
        self::assertStringNotContainsString('recordKey', $encoded);
        self::assertStringNotContainsString('currentPassword', $encoded);
        self::assertStringNotContainsString('stepUp', $encoded);
        foreach (array_column($generated, 'name') as $name) {
            self::assertDoesNotMatchRegularExpression('/(?:approve|reject|vote|step[_-]?up)/', $name);
        }
    }

    /**
     * Proves the MCP query schema mirrors shared paging, sorting, include, and aggregate bounds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedBusinessQuerySchemaMatchesTheSharedBoundsAndAggregateVocabulary(): void
    {
        $search = array_values(array_filter(
            (new McpCapabilityCatalog())->tools(),
            static fn (array $tool): bool => $tool['name'] === 'kumwe_business_search',
        ))[0] ?? null;
        self::assertIsArray($search);
        $query = $search['inputSchema']['properties']['query'];

        self::assertFalse($query['additionalProperties']);
        self::assertSame(200, $query['properties']['page_size']['maximum']);
        self::assertSame(5, $query['properties']['sorts']['maxItems']);
        self::assertSame(4, $query['properties']['projection']['properties']['includes']['maxItems']);
        self::assertSame(
            ['count', 'sum', 'min', 'max', 'avg'],
            $query['properties']['projection']['properties']['aggregates']['items']['properties']['function']['enum'],
        );
    }

    /**
     * Proves mutation planning uses a closed operation vocabulary and withholds internal bindings.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGeneratedBusinessPlanBindsClosedMutationAndCurrentStateVocabulary(): void
    {
        $plan = array_values(array_filter(
            (new McpCapabilityCatalog())->tools(),
            static fn (array $tool): bool => $tool['name'] === 'kumwe_business_plan_mutation',
        ))[0] ?? null;
        self::assertIsArray($plan);

        self::assertSame(['operationId', 'operation', 'definition'], $plan['inputSchema']['required']);
        self::assertSame(16, $plan['inputSchema']['properties']['operationId']['minLength']);
        self::assertSame([
            'create', 'update', 'archive', 'restore', 'delete', 'relate', 'unrelate', 'reorder',
            'request_action', 'execute_action',
        ], $plan['inputSchema']['properties']['operation']['enum']);
        self::assertFalse($plan['inputSchema']['additionalProperties']);
        self::assertSame(1000, $plan['inputSchema']['properties']['orderedRecordIds']['maxItems']);
        self::assertTrue($plan['inputSchema']['properties']['orderedRecordIds']['uniqueItems']);

        $output = $plan['outputSchema'];
        self::assertFalse($output['additionalProperties']);
        foreach (
            [
                'plan', 'operation_id', 'operation', 'definition_version', 'record_id',
                'record_version', 'expires_at',
            ] as $member
        ) {
            self::assertContains($member, $output['required']);
        }
        self::assertArrayNotHasKey('definition_id', $output['properties']);
        self::assertArrayNotHasKey('runtime_binding', $output['properties']);
        self::assertArrayNotHasKey('policy_binding', $output['properties']);
    }

    /** @since 2.0.0 */
    public function testBusinessReportToolsCoverExecutionAndTheBoundedExportLifecycle(): void
    {
        $tools = [];
        foreach ((new McpCapabilityCatalog())->tools() as $tool) {
            if (str_starts_with($tool['name'], 'kumwe_business_report_')) {
                $tools[$tool['name']] = $tool;
            }
        }

        self::assertSame([
            'kumwe_business_report_list',
            'kumwe_business_report_execute',
            'kumwe_business_report_export_request',
            'kumwe_business_report_export_status',
            'kumwe_business_report_export_download',
        ], array_keys($tools));
        $request = $tools['kumwe_business_report_export_request'];
        self::assertFalse($request['readOnly']);
        self::assertTrue($request['idempotent']);
        self::assertContains('operationId', $request['inputSchema']['required']);
        self::assertSame(32, $request['inputSchema']['properties']['parameters']['maxProperties']);
        self::assertFalse($request['inputSchema']['additionalProperties']);

        $download = $tools['kumwe_business_report_export_download']['outputSchema'];
        self::assertFalse($download['additionalProperties']);
        self::assertSame(1_048_576, $download['properties']['size']['maximum']);
        self::assertSame('base64', $download['properties']['encoding']['const']);
        self::assertSame('business.record.export', $tools['kumwe_business_report_export_status']['capability']);
    }
}
