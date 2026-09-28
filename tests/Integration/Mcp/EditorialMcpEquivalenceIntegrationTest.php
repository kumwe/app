<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Mcp;

use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Delivery\Console\Command\ManageContentCommand;
use Kumwe\App\Delivery\Console\Command\ManageContentModelsCommand;
use Kumwe\App\Infrastructure\Mcp\BusinessMcpHandlers;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\MachineSurfaceHarness;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves the MCP editorial tools answer and change exactly what the REST operations and the console do.
 *
 * The content editor, navigation, content models and business definitions screens already had REST and console
 * peers; MCP now reaches the same services. Reads are compared document for document with REST (and the console
 * where it prints the same document), and every MCP write is read back through REST, so a divergence in shape,
 * version handling or authorization shows up as a failed comparison. The same assertions run on MariaDB and
 * PostgreSQL.
 *
 * @since  2.0.0
 */
#[CoversClass(KumweMcpHandlers::class)]
#[CoversClass(BusinessMcpHandlers::class)]
final class EditorialMcpEquivalenceIntegrationTest extends TestCase
{
    /**
     * Harness of the running test.
     *
     * @var    ?MachineSurfaceHarness
     * @since  2.0.0
     */
    private ?MachineSurfaceHarness $harness = null;

    /**
     * Revoke every token the running test issued.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        $this->harness?->cleanup();
        $this->harness = null;
    }

    /**
     * Content and menu reads match REST and the console, and MCP menu writes land as REST reads them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContentAndMenuToolsMatchRestAndTheConsole(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $harness = $this->harness = new MachineSurfaceHarness($container, 'editorial-parity');
        $administrator = TestKernelFactory::administratorContext($container);
        $content = $container->get(ContentService::class);
        $navigation = $container->get(NavigationService::class);
        self::assertInstanceOf(ContentService::class, $content);
        self::assertInstanceOf(NavigationService::class, $navigation);
        $marker = bin2hex(random_bytes(5));
        $entry = $content->create(
            $administrator,
            'Parity entry ' . $marker,
            'parity-entry-' . $marker,
            ['body' => 'Parity body'],
        )->toArray();
        $entryId = $entry['id'];
        self::assertIsString($entryId);
        $menu = $navigation->createMenu($administrator, 'parity_menu_' . $marker, 'Parity menu');
        $capabilities = ['content.read', 'navigation.manage'];
        $rest = $harness->token('rest', $capabilities);
        $cli = $harness->token('cli', $capabilities);
        $mcp = $harness->token('mcp', $capabilities);

        $restEntry = $harness->rest($rest, 'GET', '/api/v1/content/' . $entryId);
        $cliEntry = $harness->cli(ManageContentCommand::class, $cli, ['get', '--id=' . $entryId]);
        $mcpEntry = $harness->mcp($mcp, 'kumwe_content_get', ['id' => $entryId]);
        $restMenu = $harness->rest($rest, 'GET', '/api/v1/menus/' . $menu->id);
        $mcpMenu = $harness->mcp($mcp, 'kumwe_menu_get', ['id' => $menu->id]);

        self::assertSame(200, $restEntry['status'], $restEntry['raw']);
        self::assertSame($restEntry['body'], $cliEntry['stdout']);
        self::assertSame($restEntry['body'], $mcpEntry['value']);
        self::assertSame(200, $restMenu['status'], $restMenu['raw']);
        self::assertSame($restMenu['body'], $mcpMenu['value']);

        $updated = $harness->mcp($mcp, 'kumwe_menu_update', [
            'operationId' => 'editorial-parity-menu-' . $marker . '-update',
            'id' => $menu->id,
            'version' => $menu->version,
            'handle' => 'parity_menu_' . $marker,
            'title' => 'Renamed parity menu',
        ]);
        self::assertFalse($updated['error'], (string) json_encode($updated));
        self::assertSame($harness->rest($rest, 'GET', '/api/v1/menus/' . $menu->id)['body'], $updated['value']);
        self::assertIsArray($updated['value']);
        self::assertSame('Renamed parity menu', $updated['value']['title']);
        $stale = $harness->mcp($mcp, 'kumwe_menu_delete', [
            'operationId' => 'editorial-parity-menu-' . $marker . '-stale',
            'id' => $menu->id,
            'version' => $menu->version,
        ]);
        $deleted = $harness->mcp($mcp, 'kumwe_menu_delete', [
            'operationId' => 'editorial-parity-menu-' . $marker . '-delete',
            'id' => $menu->id,
            'version' => $updated['value']['version'],
        ]);
        self::assertTrue($stale['error']);
        self::assertIsArray($stale['value']);
        self::assertSame('conflict.version', $stale['value']['code']);
        self::assertSame(['deleted' => true], $deleted['value']);
        self::assertNotContains($menu->id, array_column(
            $harness->mcp($mcp, 'kumwe_menu_list')['value']['items'] ?? [],
            'id',
        ));
        self::assertContains($harness->rest($rest, 'GET', '/api/v1/menus/' . $menu->id)['status'], [403, 404]);
    }

    /**
     * Content type and workflow reads match REST and the console, and MCP model writes land as REST reads them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContentModelToolsMatchRestAndTheConsole(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $harness = $this->harness = new MachineSurfaceHarness($container, 'models-parity');
        $capabilities = ['content.read', 'content.update'];
        $rest = $harness->token('rest', $capabilities);
        $cli = $harness->token('cli', $capabilities);
        $mcp = $harness->token('mcp', $capabilities);
        $marker = bin2hex(random_bytes(5));

        $workflow = $harness->mcp($mcp, 'kumwe_workflow_create', [
            'operationId' => 'models-parity-workflow-' . $marker,
            'handle' => 'parity-review-' . $marker,
            'name' => 'Parity review',
            'states' => [
                ['key' => 'draft', 'name' => 'Draft', 'initial' => true, 'public' => false],
                ['key' => 'approved', 'name' => 'Approved', 'initial' => false, 'public' => true],
            ],
            'transitions' => [['from' => 'draft', 'to' => 'approved', 'required_capability' => 'content.publish']],
        ]);
        self::assertFalse($workflow['error'], (string) json_encode($workflow));
        self::assertIsArray($workflow['value']);
        $workflowId = $workflow['value']['id'];
        $type = $harness->mcp($mcp, 'kumwe_content_type_create', [
            'operationId' => 'models-parity-type-' . $marker,
            'handle' => 'parity-article-' . $marker,
            'name' => 'Parity article',
            'workflow' => $workflowId,
            'schema' => [
                'type' => 'object',
                'properties' => ['body' => ['type' => 'string']],
                'required' => ['body'],
                'additionalProperties' => false,
            ],
        ]);
        self::assertFalse($type['error'], (string) json_encode($type));
        self::assertIsArray($type['value']);
        $typeId = $type['value']['id'];

        self::assertSame($harness->rest($rest, 'GET', '/api/v1/workflows/' . $workflowId)['body'], $workflow['value']);
        self::assertSame($harness->rest($rest, 'GET', '/api/v1/content-types/' . $typeId)['body'], $type['value']);
        self::assertSame(
            $harness->cli(ManageContentModelsCommand::class, $cli, ['get', '--kind=content-type', '--id=' . $typeId])
                ['stdout'],
            $harness->mcp($mcp, 'kumwe_content_type_get', ['id' => $typeId])['value'],
        );
        self::assertSame(
            $harness->rest($rest, 'GET', '/api/v1/workflows/' . $workflowId)['body'],
            $harness->mcp($mcp, 'kumwe_workflow_get', ['id' => $workflowId])['value'],
        );
        self::assertSame(
            $harness->rest($rest, 'GET', '/api/v1/content-types')['body']['items'] ?? null,
            $harness->mcp($mcp, 'kumwe_content_type_list')['value']['items'] ?? null,
        );
        self::assertSame(
            $harness->rest($rest, 'GET', '/api/v1/workflows')['body']['items'] ?? null,
            $harness->mcp($mcp, 'kumwe_workflow_list')['value']['items'] ?? null,
        );

        $newType = $harness->mcp($mcp, 'kumwe_content_type_update', [
            'operationId' => 'models-parity-type-update-' . $marker,
            'id' => $typeId,
            'version' => $type['value']['version'],
            'name' => 'Parity article v2',
            'workflow' => $workflowId,
            'schema' => [
                'type' => 'object',
                'properties' => ['body' => ['type' => 'string'], 'summary' => ['type' => 'string']],
                'required' => ['body'],
                'additionalProperties' => false,
            ],
        ]);
        $newWorkflow = $harness->mcp($mcp, 'kumwe_workflow_update', [
            'operationId' => 'models-parity-workflow-update-' . $marker,
            'id' => $workflowId,
            'version' => $workflow['value']['version'],
            'name' => 'Parity review v2',
            'states' => [
                ['key' => 'draft', 'name' => 'Draft', 'initial' => true, 'public' => false],
                ['key' => 'approved', 'name' => 'Approved', 'initial' => false, 'public' => true],
            ],
            'transitions' => [['from' => 'draft', 'to' => 'approved', 'required_capability' => 'content.publish']],
        ]);
        self::assertFalse($newType['error'], (string) json_encode($newType));
        self::assertFalse($newWorkflow['error'], (string) json_encode($newWorkflow));
        self::assertSame($harness->rest($rest, 'GET', '/api/v1/content-types/' . $typeId)['body'], $newType['value']);
        self::assertSame(
            $harness->rest($rest, 'GET', '/api/v1/workflows/' . $workflowId)['body'],
            $newWorkflow['value'],
        );
        $denied = $harness->mcp($harness->token('mcp', ['content.read']), 'kumwe_workflow_create', [
            'operationId' => 'models-parity-denied-' . $marker,
            'handle' => 'parity-denied-' . $marker,
            'name' => 'Denied',
            'states' => [['key' => 'draft', 'name' => 'Draft', 'initial' => true, 'public' => false]],
            'transitions' => [],
        ]);
        self::assertIsArray($denied['value']);
        self::assertSame('authorization.denied', $denied['value']['code']);
    }

    /**
     * Definition drafts, validation, version status and relationship reads land as REST reads them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDefinitionLifecycleAndRelationshipToolsMatchRest(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $harness = $this->harness = new MachineSurfaceHarness($container, 'definitions-parity');
        $harness->enterOrganization('machine-parity');
        $administrator = TestKernelFactory::administratorContext($container);
        $definitions = $container->get(BusinessDefinitionService::class);
        self::assertInstanceOf(BusinessDefinitionService::class, $definitions);
        $suffix = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
        $target = NeutralBusinessFixture::install(
            $container,
            $administrator,
            NeutralBusinessFixture::relationTargetDocument($suffix, Uuid::uuid7()->toString()),
        );
        $line = NeutralBusinessFixture::install(
            $container,
            $administrator,
            NeutralBusinessFixture::ownedLineDocument($suffix, Uuid::uuid7()->toString()),
        );
        $owner = NeutralBusinessFixture::install(
            $container,
            $administrator,
            NeutralBusinessFixture::relationshipOwnerDocument(
                $suffix,
                Uuid::uuid7()->toString(),
                $target->handle,
                $line->handle,
            ),
        );
        $capabilities = ['content.read', 'content.update', 'business.record.read'];
        $rest = $harness->token('rest', $capabilities);
        $mcp = $harness->token('mcp', $capabilities);

        $document = NeutralBusinessFixture::document('draft' . $suffix, Uuid::uuid7()->toString());
        $document['plural_label'] = 'Parity drafts';
        $saved = $harness->mcp($mcp, 'kumwe_business_definition_draft_save', [
            'operationId' => 'definitions-parity-save-' . $suffix,
            'definition' => $document,
        ]);
        self::assertFalse($saved['error'], (string) json_encode($saved));
        self::assertIsArray($saved['value']);
        $handle = $saved['value']['definition']['handle'];
        $validated = $harness->mcp($mcp, 'kumwe_business_definition_validate', [
            'operationId' => 'definitions-parity-validate-' . $suffix,
            'handle' => $handle,
        ]);
        $restDraft = $harness->rest($rest, 'GET', '/api/v1/business-definitions/' . $handle . '/draft');
        self::assertFalse($validated['error'], (string) json_encode($validated));
        self::assertSame('Parity drafts', $saved['value']['definition']['plural_label']);
        self::assertSame($restDraft['body'], $validated['value']);
        self::assertSame($definitions->draft($administrator, $handle)->revision, $saved['value']['revision']);
        self::assertSame(
            $restDraft['body'],
            $harness->mcp($mcp, 'kumwe_business_definition_draft', ['handle' => $handle])['value'],
        );

        $deprecated = $harness->mcp($mcp, 'kumwe_business_definition_deprecate', [
            'operationId' => 'definitions-parity-deprecate-' . $suffix,
            'handle' => $line->handle,
            'version' => $line->definitionVersion,
        ]);
        self::assertFalse($deprecated['error'], (string) json_encode($deprecated));
        self::assertIsArray($deprecated['value']);
        self::assertSame('deprecated', $deprecated['value']['status']);
        $history = $harness->rest($rest, 'GET', '/api/v1/business-definitions/' . $line->handle . '/history');
        self::assertIsArray($history['body']);
        self::assertSame('deprecated', $history['body']['items'][0]['status'] ?? null);

        $ownerRecord = Uuid::uuid7()->toString();
        $records = $container->get(\Kumwe\App\BusinessRecord\Application\BusinessRecordService::class);
        self::assertInstanceOf(\Kumwe\App\BusinessRecord\Application\BusinessRecordService::class, $records);
        $records->create(new \Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand(
            $administrator,
            $owner->handle,
            ['title' => 'Parity owner'],
            NeutralBusinessFixture::idempotencyKey('definitions-parity-owner-' . $suffix),
            recordId: $ownerRecord,
        ));
        $restRelation = $harness->rest(
            $rest,
            'GET',
            '/api/v1/business/records/' . $owner->handle . '/' . $ownerRecord . '/relations/lines',
        );
        $mcpRelation = $harness->mcp($mcp, 'kumwe_business_relation_read', [
            'definition' => $owner->handle,
            'record' => $ownerRecord,
            'relationship' => 'lines',
        ]);
        self::assertSame(200, $restRelation['status'], $restRelation['raw']);
        self::assertFalse($mcpRelation['error'], (string) json_encode($mcpRelation));
        self::assertIsArray($mcpRelation['value']);
        self::assertSame($ownerRecord, $restRelation['body']['record_id'] ?? $restRelation['body']['id'] ?? null);
        self::assertStringContainsString($ownerRecord, (string) json_encode($mcpRelation['value']));

        foreach (['supersede' => $target, 'reject' => $owner] as $action => $retired) {
            $result = $harness->mcp($mcp, 'kumwe_business_definition_' . $action, [
                'operationId' => 'definitions-parity-' . $action . '-' . $suffix,
                'handle' => $retired->handle,
                'version' => $retired->definitionVersion,
            ]);
            self::assertFalse($result['error'], (string) json_encode($result));
            self::assertIsArray($result['value']);
            self::assertSame(
                $action === 'supersede' ? 'superseded' : 'rejected',
                $result['value']['status'],
            );
        }
    }
}
