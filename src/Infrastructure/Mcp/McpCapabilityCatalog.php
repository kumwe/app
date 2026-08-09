<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Mcp;

/**
 * Single declaration of the MCP surface a Kumwe release publishes.
 *
 * Every tool, resource and prompt an MCP client can reach is described here once: its name, the
 * `KumweMcpHandlers` method that serves it, the capability that handler requires, the annotation hints
 * a client uses to decide how cautiously to call it, and closed JSON Schemas for input and output.
 * `KumweMcpServerFactory` registers a server straight from this list, and the discovery tool and the
 * `kumwe://capabilities` resource publish a name-only summary of it, so widening or narrowing the
 * surface is one edit here rather than parallel edits in the factory and the handlers. The catalogue is
 * pure data with no dependencies and no state, which is why the container shares one instance of it.
 *
 * Two properties hold across the whole list. Every entry that is not read-only declares an
 * `operationId` and is annotated idempotent, so `McpMutationGuard` can fence a first attempt and replay
 * a retry instead of applying it twice. And work that would need the caller's current password re-proved
 * is kept off the surface rather than offered and refused: no tool composes a destructive schema purge
 * plan, and the schema approval tool declines high-impact plans, since an agent cannot supply that
 * step-up and a tool that always fails closed is worse than an absent one.
 *
 * @since  2.0.0
 */
final class McpCapabilityCatalog
{
    /**
     * List every tool this release publishes, in the order the server registers them.
     *
     * Each entry names the handler method that serves it and the capability that handler enforces.
     * A null capability means no single capability decides the call: `kumwe_discover` is open to any
     * authenticated caller, and `kumwe_content_transition` authorizes the specific transition it is
     * asked to perform. Mutating entries always carry an `operationId` property so a retry deduplicates.
     *
     * @return  list<array{
     *            name: string, title: string, description: string, handler: string,
     *            capability: string|null, readOnly: bool, destructive: bool, idempotent: bool,
     *            inputSchema: array<string, mixed>, outputSchema: array<string, mixed>
     *          }>
     *
     * @since   2.0.0
     */
    public function tools(): array
    {
        $object = ['type' => 'object', 'additionalProperties' => true];

        return [
            $this->tool(
                'kumwe_discover',
                'Discover Kumwe',
                'Discover the available Kumwe MCP surface.',
                'discover',
                null,
                true,
                false,
                true,
                [],
                $object
            ),
            $this->tool(
                'kumwe_content_list',
                'List content',
                'List content entries.',
                'listContent',
                'content.read',
                true,
                false,
                true,
                ['includeDeleted' => ['type' => 'boolean']],
                $object
            ),
            $this->tool(
                'kumwe_content_create',
                'Create content',
                'Create a draft page.',
                'createContent',
                'content.create',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(),
                    'title' => ['type' => 'string'], 'slug' => ['type' => 'string'],
                    'body' => ['type' => 'string'], 'contentType' => ['type' => 'string'],
                    'data' => ['type' => 'object', 'additionalProperties' => true],
                ],
                $object,
                ['operationId', 'title', 'slug']
            ),
            $this->tool(
                'kumwe_content_update',
                'Update content',
                'Update a page with optimistic concurrency.',
                'updateContent',
                'content.update',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(),
                    'id' => ['type' => 'string'], 'version' => ['type' => 'integer', 'minimum' => 1],
                    'title' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'body' => ['type' => 'string'],
                    'data' => ['type' => 'object', 'additionalProperties' => true],
                ],
                $object,
                ['operationId', 'id', 'version', 'title', 'slug']
            ),
            $this->tool(
                'kumwe_content_transition',
                'Transition content',
                'Apply an authorized workflow transition.',
                'transitionContent',
                null,
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(),
                    'id' => ['type' => 'string'], 'version' => ['type' => 'integer', 'minimum' => 1],
                    'status' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]{0,39}$'],
                ],
                $object,
                ['operationId', 'id', 'version', 'status']
            ),
            $this->tool(
                'kumwe_content_trash',
                'Trash content',
                'Move content to trash.',
                'trashContent',
                'content.delete',
                false,
                true,
                true,
                [
                    'operationId' => $this->operationId(), 'id' => ['type' => 'string'],
                    'version' => ['type' => 'integer', 'minimum' => 1],
                ],
                $object,
                ['operationId', 'id', 'version']
            ),
            $this->tool(
                'kumwe_content_restore',
                'Restore content',
                'Restore trashed content.',
                'restoreContent',
                'content.restore',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(), 'id' => ['type' => 'string'],
                    'version' => ['type' => 'integer', 'minimum' => 1],
                ],
                $object,
                ['operationId', 'id', 'version']
            ),
            $this->tool(
                'kumwe_menu_list',
                'List menus',
                'List site menus.',
                'listMenus',
                'navigation.manage',
                true,
                false,
                true,
                [],
                $object
            ),
            $this->tool(
                'kumwe_menu_create',
                'Create menu',
                'Create a site menu.',
                'createMenu',
                'navigation.manage',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(),
                    'handle' => ['type' => 'string'], 'title' => ['type' => 'string'],
                ],
                $object,
                ['operationId', 'handle', 'title']
            ),
            $this->tool(
                'kumwe_menu_item_list',
                'List menu items',
                'List a menu tree.',
                'listMenuItems',
                'navigation.manage',
                true,
                false,
                true,
                ['menuId' => ['type' => 'string']],
                $object,
                ['menuId']
            ),
            $this->tool(
                'kumwe_menu_item_get',
                'Read menu item',
                'Read one menu item and its resolved target metadata.',
                'getMenuItem',
                'navigation.manage',
                true,
                false,
                true,
                ['id' => ['type' => 'string']],
                $object,
                ['id']
            ),
            $this->tool(
                'kumwe_menu_item_create',
                'Create menu item',
                'Create a menu item.',
                'createMenuItem',
                'navigation.manage',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(), 'menuId' => ['type' => 'string'],
                    'title' => ['type' => 'string'], 'slug' => ['type' => 'string'],
                    'position' => ['type' => 'integer', 'minimum' => 0], 'parentId' => ['type' => 'string'],
                    'targetType' => ['type' => 'string', 'enum' => ['content', 'anchor', 'url']],
                    'contentId' => ['type' => 'string'], 'targetUrl' => ['type' => 'string'],
                ],
                $object,
                ['operationId', 'menuId', 'title', 'slug', 'targetType']
            ),
            $this->tool(
                'kumwe_menu_item_update',
                'Update menu item',
                'Update a versioned menu item, its parent and its typed target.',
                'updateMenuItem',
                'navigation.manage',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(), 'id' => ['type' => 'string'],
                    'version' => ['type' => 'integer', 'minimum' => 1],
                    'title' => ['type' => 'string'], 'slug' => ['type' => 'string'],
                    'position' => ['type' => 'integer', 'minimum' => 0], 'parentId' => ['type' => 'string'],
                    'targetType' => ['type' => 'string', 'enum' => ['content', 'anchor', 'url']],
                    'contentId' => ['type' => 'string'], 'targetUrl' => ['type' => 'string'],
                ],
                $object,
                ['operationId', 'id', 'version', 'title', 'slug']
            ),
            $this->tool(
                'kumwe_menu_item_delete',
                'Delete menu item',
                'Delete one menu item at an expected version.',
                'deleteMenuItem',
                'navigation.manage',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(), 'id' => ['type' => 'string'],
                    'version' => ['type' => 'integer', 'minimum' => 1],
                ],
                $object,
                ['operationId', 'id', 'version']
            ),
            $this->tool(
                'kumwe_settings_get',
                'Read settings',
                'Read site configuration.',
                'getSettings',
                'settings.manage',
                true,
                false,
                true,
                [],
                $object
            ),
            $this->tool(
                'kumwe_settings_update',
                'Update settings',
                'Update all site configuration values.',
                'updateSettings',
                'settings.manage',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(),
                    'siteName' => ['type' => 'string'],
                    'homepageContentId' => ['type' => 'string', 'format' => 'uuid'],
                    'defaultLocale' => ['type' => 'string'], 'timezone' => ['type' => 'string'],
                    'searchIndexingEnabled' => ['type' => 'boolean'],
                    'presentation' => $this->presentation(),
                ],
                $object,
                [
                    'operationId', 'siteName', 'homepageContentId', 'defaultLocale', 'timezone',
                    'searchIndexingEnabled', 'presentation',
                ]
            ),
            $this->tool(
                'kumwe_user_list',
                'List users',
                'List users and their assignments.',
                'listUsers',
                'users.manage',
                true,
                false,
                true,
                [],
                $object
            ),
            $this->tool(
                'kumwe_user_update',
                'Update user',
                'Update user profile and status.',
                'updateUser',
                'users.manage',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(), 'id' => ['type' => 'string'],
                    'version' => ['type' => 'integer', 'minimum' => 1], 'email' => ['type' => 'string'],
                    'displayName' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => ['active', 'disabled', 'suspended']],
                ],
                $object,
                ['operationId', 'id', 'version', 'email', 'displayName', 'status']
            ),
            $this->tool(
                'kumwe_role_list',
                'List roles',
                'List roles and capabilities.',
                'listRoles',
                'users.manage',
                true,
                false,
                true,
                [],
                $object
            ),
            $this->tool(
                'kumwe_role_create',
                'Create role',
                'Create a permission role.',
                'createRole',
                'users.manage',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(), 'code' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                ],
                $object,
                ['operationId', 'code', 'name']
            ),
            $this->tool(
                'kumwe_token_list',
                'List API tokens',
                'List token metadata without token secrets.',
                'listTokens',
                'users.manage',
                true,
                false,
                true,
                [],
                $object
            ),
            $this->tool(
                'kumwe_token_revoke',
                'Revoke API token',
                'Immediately revoke an API or MCP token.',
                'revokeToken',
                'users.manage',
                false,
                true,
                true,
                [
                    'operationId' => $this->operationId(), 'tokenId' => ['type' => 'string'],
                ],
                $object,
                ['operationId', 'tokenId']
            ),
            $this->tool(
                'kumwe_token_rotate',
                'Rotate API token',
                'Rotate a token and return its replacement secret once.',
                'rotateToken',
                'users.manage',
                false,
                true,
                true,
                [
                    'operationId' => $this->operationId(), 'tokenId' => ['type' => 'string'],
                    'name' => ['type' => 'string'], 'expiresAt' => ['type' => 'string'],
                ],
                $object,
                ['operationId', 'tokenId', 'name']
            ),
            $this->tool(
                'kumwe_token_revoke_subject_site',
                'Revoke user tokens for site',
                'Revoke every token for one user in the authenticated site only.',
                'revokeSubjectSiteTokens',
                'users.manage',
                false,
                true,
                true,
                [
                    'operationId' => $this->operationId(), 'userId' => ['type' => 'string'],
                    'reason' => ['type' => 'string'],
                ],
                $object,
                ['operationId', 'userId', 'reason'],
            ),
            $this->tool(
                'kumwe_token_emergency_revoke_subject',
                'Emergency revoke user tokens',
                'Globally invalidate every token for one user by advancing their security epoch.',
                'emergencyRevokeSubjectTokens',
                'users.manage',
                false,
                true,
                true,
                [
                    'operationId' => $this->operationId(), 'userId' => ['type' => 'string'],
                    'reason' => ['type' => 'string'],
                ],
                $object,
                ['operationId', 'userId', 'reason']
            ),
            $this->tool(
                'kumwe_trust_key_list',
                'List extension trust keys',
                'List trust keys and the active releases that still depend on each key.',
                'listTrustKeys',
                'extensions.manage',
                true,
                false,
                true,
                [],
                $object,
            ),
            $this->tool(
                'kumwe_trust_key_add',
                'Add extension trust key',
                'Add a constrained and expiring Ed25519 extension signing key.',
                'addTrustKey',
                'extensions.manage',
                false,
                false,
                true,
                $this->trustKeyProperties(),
                $object,
                ['operationId', 'keyId', 'publicKeyBase64', 'vendorNamespace', 'extensionPattern', 'expiresAt'],
            ),
            $this->tool(
                'kumwe_trust_key_rotate',
                'Begin extension trust-key rotation',
                'Add a replacement key while retaining the old key during the overlap period.',
                'rotateTrustKey',
                'extensions.manage',
                false,
                false,
                true,
                [...$this->trustKeyProperties(), 'oldKeyId' => ['type' => 'string']],
                $object,
                [
                    'operationId', 'oldKeyId', 'newKeyId', 'publicKeyBase64',
                    'vendorNamespace', 'extensionPattern', 'expiresAt',
                ],
            ),
            $this->tool(
                'kumwe_trust_key_revoke',
                'Finalize or emergency-revoke trust key',
                'Finalize rotation only after upgrades, or quarantine affected releases during an emergency.',
                'revokeTrustKey',
                'extensions.manage',
                false,
                true,
                true,
                [
                    'operationId' => $this->operationId(), 'keyId' => ['type' => 'string'],
                    'reason' => ['type' => 'string'], 'emergency' => ['type' => 'boolean'],
                ],
                $object,
                ['operationId', 'keyId', 'reason'],
            ),
            $this->tool(
                'kumwe_extension_list',
                'List extensions',
                'List installed extensions.',
                'listExtensions',
                'extensions.manage',
                true,
                false,
                true,
                [],
                $object
            ),
            $this->tool(
                'kumwe_extension_activate',
                'Activate extension',
                'Activate an installed extension.',
                'activateExtension',
                'extensions.manage',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(),
                    'identifier' => ['type' => 'string'],
                    'surface' => ['type' => ['string', 'null'], 'enum' => ['site', 'administrator', null]],
                    'currentPassword' => $this->currentPassword(),
                ],
                $object,
                ['operationId', 'identifier']
            ),
            $this->tool(
                'kumwe_extension_disable',
                'Disable extension',
                'Disable an installed extension.',
                'disableExtension',
                'extensions.manage',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(),
                    'identifier' => ['type' => 'string'],
                    'currentPassword' => $this->currentPassword(),
                ],
                $object,
                ['operationId', 'identifier']
            ),
            $this->tool(
                'kumwe_extension_uninstall',
                'Uninstall extension',
                'Uninstall an extension.',
                'uninstallExtension',
                'extensions.manage',
                false,
                true,
                true,
                [
                    'operationId' => $this->operationId(),
                    'identifier' => ['type' => 'string'],
                    'currentPassword' => $this->currentPassword(),
                ],
                $object,
                ['operationId', 'identifier']
            ),
            $this->tool(
                'kumwe_business_definition_list',
                'List business definitions',
                'List the versioned business entity definition catalogue.',
                'listBusinessDefinitions',
                'content.read',
                true,
                false,
                true,
                [],
                $object
            ),
            $this->tool(
                'kumwe_business_definition_get',
                'Read a business definition',
                'Read a published business entity definition version.',
                'getBusinessDefinition',
                'content.read',
                true,
                false,
                true,
                ['handle' => ['type' => 'string'], 'version' => ['type' => 'integer', 'minimum' => 1]],
                $object,
                ['handle']
            ),
            $this->tool(
                'kumwe_business_definition_draft',
                'Read a definition draft',
                'Read the working draft of a business entity definition.',
                'getBusinessDefinitionDraft',
                'content.read',
                true,
                false,
                true,
                ['handle' => ['type' => 'string']],
                $object,
                ['handle']
            ),
            $this->tool(
                'kumwe_business_definition_history',
                'List definition versions',
                'List every published version of a business entity definition.',
                'listBusinessDefinitionHistory',
                'content.read',
                true,
                false,
                true,
                ['handle' => ['type' => 'string']],
                $object,
                ['handle']
            ),
            $this->tool(
                'kumwe_business_definition_compatibility',
                'Preview a compatibility plan',
                'Preview the compatibility plan the current draft would publish.',
                'previewBusinessDefinitionCompatibility',
                'content.read',
                true,
                false,
                true,
                ['handle' => ['type' => 'string']],
                $object,
                ['handle']
            ),
            $this->tool(
                'kumwe_business_definition_publish',
                'Publish a definition',
                'Publish the working draft as a new immutable definition version.',
                'publishBusinessDefinition',
                'content.update',
                false,
                false,
                true,
                [
                    'operationId' => ['type' => 'string'],
                    'handle' => ['type' => 'string'],
                    'expectedRevision' => ['type' => 'integer', 'minimum' => 1],
                    'confirmed' => ['type' => 'boolean'],
                ],
                $object,
                ['operationId', 'handle', 'expectedRevision']
            ),
            $this->tool(
                'kumwe_business_schema_definitions',
                'List plannable definitions',
                'List published definitions a schema plan can target.',
                'listSchemaDefinitions',
                'business.schema.read',
                true,
                false,
                true,
                [],
                $object
            ),
            $this->tool(
                'kumwe_business_schema_plan_list',
                'List schema plans',
                'List business schema plans and their status.',
                'listSchemaPlans',
                'business.schema.read',
                true,
                false,
                true,
                [],
                $object
            ),
            $this->tool(
                'kumwe_business_schema_plan_get',
                'Read a schema plan',
                'Read a schema plan with its durable step journal and canonical checksum.',
                'getSchemaPlan',
                'business.schema.read',
                true,
                false,
                true,
                ['planId' => ['type' => 'string']],
                $object,
                ['planId']
            ),
            $this->tool(
                'kumwe_business_schema_plan_create',
                'Create a schema plan',
                'Compile a deterministic schema plan for a published definition. Runs no DDL.',
                'createSchemaPlan',
                'business.schema.plan',
                false,
                false,
                true,
                ['operationId' => ['type' => 'string'], 'definitionId' => ['type' => 'string']],
                $object,
                ['operationId', 'definitionId']
            ),
            $this->tool(
                'kumwe_business_schema_plan_approve',
                'Approve a schema plan',
                'Approve the exact inspected plan checksum. High-impact plans are refused here.',
                'approveSchemaPlan',
                'business.schema.approve',
                false,
                false,
                true,
                [
                    'operationId' => ['type' => 'string'],
                    'planId' => ['type' => 'string'],
                    'expectedChecksum' => ['type' => 'string'],
                    'recoveryEvidenceId' => ['type' => 'string'],
                ],
                $object,
                ['operationId', 'planId', 'expectedChecksum']
            ),
            $this->tool(
                'kumwe_business_schema_plan_execute',
                'Execute a schema plan',
                'Apply an approved schema plan under the schema lock. Changes physical tables.',
                'executeSchemaPlan',
                'business.schema.execute',
                false,
                true,
                true,
                ['operationId' => ['type' => 'string'], 'planId' => ['type' => 'string']],
                $object,
                ['operationId', 'planId']
            ),
            $this->tool(
                'kumwe_business_schema_plan_recover',
                'Recover a schema plan',
                'Resume or reconcile an interrupted schema plan after inspecting its journal.',
                'recoverSchemaPlan',
                'business.schema.recover',
                false,
                true,
                true,
                ['operationId' => ['type' => 'string'], 'planId' => ['type' => 'string']],
                $object,
                ['operationId', 'planId']
            ),
            $this->tool(
                'kumwe_schedule_list',
                'List schedules',
                'List recurring automation schedules.',
                'listSchedules',
                'automation.manage',
                true,
                false,
                true,
                [],
                $object
            ),
            $this->tool(
                'kumwe_job_list',
                'List jobs',
                'List recent and failed jobs.',
                'listJobs',
                'automation.manage',
                true,
                false,
                true,
                ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500]],
                $object
            ),
            $this->tool(
                'kumwe_schedule_create',
                'Create schedule',
                'Create a recurring automation schedule.',
                'createSchedule',
                'automation.manage',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(),
                    'name' => ['type' => 'string'], 'cron' => ['type' => 'string'],
                    'jobType' => ['type' => 'string'], 'timezone' => ['type' => 'string'],
                    'queue' => ['type' => 'string'],
                ],
                $object,
                ['operationId', 'name', 'cron', 'jobType']
            ),
            $this->tool(
                'kumwe_schedule_update',
                'Update schedule',
                'Enable or disable a schedule.',
                'setScheduleEnabled',
                'automation.manage',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(), 'id' => ['type' => 'string'],
                    'version' => ['type' => 'integer', 'minimum' => 1], 'enabled' => ['type' => 'boolean'],
                ],
                $object,
                ['operationId', 'id', 'version', 'enabled']
            ),
            $this->tool(
                'kumwe_schedule_delete',
                'Delete schedule',
                'Delete a recurring schedule.',
                'deleteSchedule',
                'automation.manage',
                false,
                true,
                true,
                [
                    'operationId' => $this->operationId(), 'id' => ['type' => 'string'],
                    'version' => ['type' => 'integer', 'minimum' => 1],
                ],
                $object,
                ['operationId', 'id', 'version']
            ),
            $this->tool(
                'kumwe_job_retry',
                'Retry job',
                'Retry a failed job.',
                'retryJob',
                'automation.manage',
                false,
                false,
                true,
                ['operationId' => $this->operationId(), 'id' => ['type' => 'string']],
                $object,
                ['operationId', 'id']
            ),
            $this->tool(
                'kumwe_job_cancel',
                'Cancel job',
                'Cancel a pending job.',
                'cancelJob',
                'automation.manage',
                false,
                true,
                true,
                ['operationId' => $this->operationId(), 'id' => ['type' => 'string']],
                $object,
                ['operationId', 'id']
            ),
        ];
    }

    /**
     * List the readable resources this release publishes.
     *
     * The one entry serves `kumwe://capabilities` as JSON from `capabilityResource`, which hands a
     * client the same summary the discovery tool returns without spending a tool call to get it.
     *
     * @return  list<array<string, string>>  One entry per resource, carrying its `uri`, `name`, `title`,
     *          `description`, `mimeType` and the handler method that serves it.
     *
     * @since   2.0.0
     */
    public function resources(): array
    {
        return [[
            'uri' => 'kumwe://capabilities', 'name' => 'kumwe_capabilities',
            'title' => 'Kumwe capability catalog',
            'description' => 'The tools and resources exposed by this Kumwe release.',
            'mimeType' => 'application/json', 'handler' => 'capabilityResource',
        ]];
    }

    /**
     * List the prompt templates this release publishes.
     *
     * The one entry exposes `kumwe_site_review`, served by `siteReviewPrompt`, which turns a review
     * focus into a single user message asking for explicit proposed changes.
     *
     * @return  list<array<string, string>>  One entry per prompt, carrying its `name`, `title`,
     *          `description` and the handler method that builds the messages.
     *
     * @since   2.0.0
     */
    public function prompts(): array
    {
        return [[
            'name' => 'kumwe_site_review', 'title' => 'Review a Kumwe site',
            'description' => 'Prepare a site review using authorized Kumwe tools.', 'handler' => 'siteReviewPrompt',
        ]];
    }

    /**
     * Summarise the surface as names only, for the discovery tool and the capability resource.
     *
     * Both of those reach this without a capability check, so the summary deliberately carries no
     * schemas, handler methods or capability requirements — only the identifiers a client needs in
     * order to ask for anything more.
     *
     * @return  array<string, string|list<string>>  Keyed `product`, `mode`, `tools`, `resources` and
     *          `prompts`; the last three list tool names, resource URIs and prompt names in catalogue
     *          order.
     *
     * @since   2.0.0
     */
    public function publicSummary(): array
    {
        return [
            'product' => 'Kumwe CMS',
            'mode' => 'capability_protected_read_write',
            'tools' => array_column($this->tools(), 'name'),
            'resources' => array_column($this->resources(), 'uri'),
            'prompts' => array_column($this->prompts(), 'name'),
        ];
    }

    /**
     * Assemble one catalogue entry from its identity, its annotation hints and its schema fragments.
     *
     * The input schema is always a closed object — `additionalProperties` is false — so an argument no
     * property names is rejected by the server before a handler is reached.
     *
     * @param   string                $name         Tool name a client calls, stable for the release.
     * @param   string                $title        Short label, reused as the annotation title.
     * @param   string                $description  One line telling a client what the tool is for.
     * @param   string                $handler      Method on `KumweMcpHandlers` this tool is bound to.
     * @param   ?string               $capability   Capability the handler requires, or null when
     *          authentication alone admits the call or the handler authorizes each action itself.
     * @param   bool                  $readOnly     True when the tool only reads; false marks a mutation.
     * @param   bool                  $destructive  True when a successful call removes or overwrites state
     *          the caller cannot simply rebuild, which clients may use to prompt for confirmation.
     * @param   bool                  $idempotent   True when repeating the call with the same arguments
     *          leaves the same end state.
     * @param   array<string, mixed>  $properties   JSON Schema property map of the tool's input object.
     * @param   array<string, mixed>  $output       JSON Schema published as the tool's output schema.
     * @param   list<string>          $required     Input property names a client must supply.
     *
     * @return  array{
     *            name: string, title: string, description: string, handler: string,
     *            capability: string|null, readOnly: bool, destructive: bool, idempotent: bool,
     *            inputSchema: array<string, mixed>, outputSchema: array<string, mixed>
     *          }
     *
     * @since   2.0.0
     */
    private function tool(
        string $name,
        string $title,
        string $description,
        string $handler,
        ?string $capability,
        bool $readOnly,
        bool $destructive,
        bool $idempotent,
        array $properties,
        array $output,
        array $required = [],
    ): array {
        return [
            'name' => $name, 'title' => $title, 'description' => $description, 'handler' => $handler,
            'capability' => $capability, 'readOnly' => $readOnly, 'destructive' => $destructive,
            'idempotent' => $idempotent,
            'inputSchema' => [
                'type' => 'object', 'properties' => $properties, 'required' => $required,
                'additionalProperties' => false,
            ],
            'outputSchema' => $output,
        ];
    }

    /**
     * Return the length-bounded schema fragment most mutating tools publish for their `operationId`.
     *
     * Declaring the window once keeps it identical wherever it is reused, so a client is told the same
     * bounds `McpMutationGuard` enforces before it claims a lease under the identifier. The business
     * definition and schema tools declare `operationId` as a plain string instead and leave the length
     * entirely to the guard.
     *
     * @return  array<string, int|string>  A string schema constrained to 16 to 128 characters.
     *
     * @since   2.0.0
     */
    private function operationId(): array
    {
        return ['type' => 'string', 'minLength' => 16, 'maxLength' => 128];
    }

    /**
     * Return the input properties the trust-key add and rotate tools share.
     *
     * Both key names are offered: `kumwe_trust_key_add` requires `keyId`, while `kumwe_trust_key_rotate`
     * spreads this map, adds `oldKeyId` and requires `newKeyId`. Which of the two a call must send is
     * therefore decided by each tool's required list, not by this fragment.
     *
     * @return  array<string, array<string, mixed>>  One schema fragment per shared property name.
     *
     * @since   2.0.0
     */
    private function trustKeyProperties(): array
    {
        return [
            'operationId' => $this->operationId(),
            'keyId' => ['type' => 'string'],
            'newKeyId' => ['type' => 'string'],
            'publicKeyBase64' => ['type' => 'string'],
            'vendorNamespace' => ['type' => 'string'],
            'extensionPattern' => ['type' => 'string'],
            'expiresAt' => ['type' => 'string'],
        ];
    }

    /**
     * Return the schema fragment for the optional step-up password on the extension tools.
     *
     * The property is nullable, so a client with no password to offer may omit it and leave the
     * extension manager to decide whether the operation needs step-up at all. It is marked `writeOnly`
     * to say the value only ever travels inbound: it is never part of a result and is not to be cached
     * with one.
     *
     * @return  array<string, bool|int|string|list<string>>  A nullable, write-only string schema.
     *
     * @since   2.0.0
     */
    private function currentPassword(): array
    {
        return [
            'type' => ['string', 'null'],
            'minLength' => 1,
            'maxLength' => 4_096,
            'writeOnly' => true,
        ];
    }

    /**
     * Build the closed schema for the presentation object `kumwe_settings_update` accepts.
     *
     * Every property of the theme, of each colour scheme and of a scheme's ten colour roles is required,
     * and each colour must be a six-digit hex value, so a settings update replaces the presentation
     * whole instead of leaving a scheme partly defined. One to twelve schemes may be supplied.
     *
     * @return  array<string, mixed>  Object schema published as the `presentation` input property.
     *
     * @since   2.0.0
     */
    private function presentation(): array
    {
        $colors = [];
        foreach (
            [
            'navy', 'ink', 'muted', 'canvas', 'surface', 'border', 'accent', 'accent_strong',
            'accent_soft', 'on_accent',
            ] as $color
        ) {
            $colors[$color] = ['type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$'];
        }

        return [
            'type' => 'object',
            'properties' => [
                'logo' => ['type' => 'string', 'maxLength' => 2_048],
                'footer_text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'primary_menu' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]*$'],
                'active_scheme' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]*$'],
                'button_style' => ['type' => 'string', 'enum' => ['solid', 'soft', 'outline']],
                'button_shape' => ['type' => 'string', 'enum' => ['square', 'rounded', 'pill']],
                'header_style' => ['type' => 'string', 'enum' => ['solid', 'glass', 'borderless']],
                'schemes' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 12,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'handle' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]*$'],
                            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                            'color_mode' => ['type' => 'string', 'enum' => ['light', 'dark']],
                            'colors' => [
                                'type' => 'object',
                                'properties' => $colors,
                                'required' => array_keys($colors),
                                'additionalProperties' => false,
                            ],
                        ],
                        'required' => ['handle', 'name', 'color_mode', 'colors'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [
                'logo', 'footer_text', 'primary_menu', 'active_scheme', 'button_style', 'button_shape',
                'header_style', 'schemes',
            ],
            'additionalProperties' => false,
        ];
    }
}
