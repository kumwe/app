<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

/**
 * Single declaration of the MCP surface a Kumwe release publishes.
 *
 * Every tool, resource and prompt an MCP client can reach is described here once: its name, the
 * `KumweMcpHandlers` method that serves it, the literal or dynamic capability resolver that handler uses,
 * the mutation-guard route, the annotation hints
 * a client uses to decide how cautiously to call it, the `McpRiskClass` that says what calling it
 * costs, the non-MCP route an operator takes instead, and explicit JSON Schemas for input and output.
 * `KumweMcpServerFactory` registers a server straight from this list, and the discovery tool and the
 * `kumwe://capabilities` resource publish both its names and its operator-facing risk metadata, so
 * widening or narrowing the surface is one edit here rather than parallel edits in the factory and the
 * handlers. The catalogue is pure data with no dependencies and no state, which is why the container
 * shares one instance of it.
 *
 * Three properties hold across the whole list, and `McpCatalogValidator` proves each of them against the
 * registered handler call graph before a
 * server is built rather than leaving them to review. Every entry that is not read-only declares an
 * `operationId` and is annotated idempotent, so `McpMutationGuard` can fence a first attempt and replay
 * a retry instead of applying it twice. Every entry declares one risk class, and its annotations and
 * capability must agree with the class it claims. No tool accepts an authentication secret or returns a
 * newly issued credential: work that needs the caller's current password re-proved, or that creates a
 * replacement token secret, stays off the surface rather than being offered and refused. No tool composes
 * a destructive schema purge plan, the schema approval tool declines high-impact plans, and the three
 * extension-lifecycle tools fail closed on the one change that demands step-up, taking over or removing
 * the live administrator theme. That step-up route is the browser or protected REST path; the console can
 * restore the built-in administrator theme for break-glass recovery but cannot perform the step-up change.
 *
 * @since  2.0.0
 */
final class McpCapabilityCatalog
{
    /**
     * Risk class and non-MCP alternative for every published tool, keyed by tool name.
     *
     * This is the whole taxonomy on one screen, which is the point of holding it here rather than
     * scattering two more arguments across every declaration: an operator or reviewer deciding what a
     * token may hold reads the classification in one pass. `McpCatalogValidator` refuses a
     * catalogue whose declarations and this table disagree, and `tools()` refuses to publish a tool
     * this table does not classify, so the map cannot fall behind the surface it describes.
     *
     * @var    array<string, array{McpRiskClass, string}>
     * @since  2.0.0
     */
    private const array RISK = [
        'kumwe_discover' => [McpRiskClass::Read, self::VIA_DOCUMENTATION],
        'kumwe_content_list' => [McpRiskClass::Read, self::VIA_CONTENT],
        'kumwe_content_create' => [McpRiskClass::ScopedWrite, self::VIA_CONTENT],
        'kumwe_content_update' => [McpRiskClass::ScopedWrite, self::VIA_CONTENT],
        'kumwe_content_transition' => [McpRiskClass::ScopedWrite, self::VIA_CONTENT],
        'kumwe_content_trash' => [McpRiskClass::ScopedWrite, self::VIA_CONTENT],
        'kumwe_content_restore' => [McpRiskClass::ScopedWrite, self::VIA_CONTENT],
        'kumwe_menu_list' => [McpRiskClass::Read, self::VIA_NAVIGATION],
        'kumwe_menu_create' => [McpRiskClass::ScopedWrite, self::VIA_NAVIGATION],
        'kumwe_menu_item_list' => [McpRiskClass::Read, self::VIA_NAVIGATION],
        'kumwe_menu_item_get' => [McpRiskClass::Read, self::VIA_NAVIGATION],
        'kumwe_menu_item_create' => [McpRiskClass::ScopedWrite, self::VIA_NAVIGATION],
        'kumwe_menu_item_update' => [McpRiskClass::ScopedWrite, self::VIA_NAVIGATION],
        'kumwe_menu_item_delete' => [McpRiskClass::Destructive, self::VIA_NAVIGATION],
        'kumwe_settings_get' => [McpRiskClass::Read, self::VIA_SETTINGS],
        'kumwe_settings_update' => [McpRiskClass::ScopedWrite, self::VIA_SETTINGS],
        'kumwe_user_list' => [McpRiskClass::Read, self::VIA_IDENTITY],
        'kumwe_user_update' => [McpRiskClass::InstallationGlobal, self::VIA_IDENTITY],
        'kumwe_role_list' => [McpRiskClass::Read, self::VIA_IDENTITY],
        'kumwe_role_create' => [McpRiskClass::InstallationGlobal, self::VIA_IDENTITY],
        'kumwe_token_list' => [McpRiskClass::Read, self::VIA_TOKENS],
        'kumwe_token_revoke' => [McpRiskClass::Credential, self::VIA_TOKENS],
        'kumwe_token_revoke_subject_site' => [McpRiskClass::Credential, self::VIA_TOKENS],
        'kumwe_token_emergency_revoke_subject' => [McpRiskClass::InstallationGlobal, self::VIA_TOKENS],
        'kumwe_trust_key_list' => [McpRiskClass::Read, self::VIA_TRUST],
        'kumwe_trust_key_add' => [McpRiskClass::Trust, self::VIA_TRUST],
        'kumwe_trust_key_rotate' => [McpRiskClass::Trust, self::VIA_TRUST],
        'kumwe_trust_key_revoke' => [McpRiskClass::Trust, self::VIA_TRUST],
        'kumwe_extension_list' => [McpRiskClass::Read, self::VIA_EXTENSIONS],
        'kumwe_extension_activate' => [McpRiskClass::Trust, self::VIA_EXTENSIONS],
        'kumwe_extension_disable' => [McpRiskClass::Trust, self::VIA_EXTENSIONS],
        'kumwe_extension_uninstall' => [McpRiskClass::Trust, self::VIA_EXTENSIONS],
        'kumwe_business_discover' => [McpRiskClass::Read, self::VIA_RECORDS],
        'kumwe_business_inspect' => [McpRiskClass::Read, self::VIA_RECORDS],
        'kumwe_business_view' => [McpRiskClass::Read, self::VIA_RECORDS],
        'kumwe_business_search' => [McpRiskClass::Read, self::VIA_RECORDS],
        'kumwe_business_read' => [McpRiskClass::Read, self::VIA_RECORDS],
        'kumwe_business_history' => [McpRiskClass::Read, self::VIA_RECORDS],
        'kumwe_business_plan_mutation' => [McpRiskClass::Read, self::VIA_RECORDS],
        'kumwe_business_create' => [McpRiskClass::ScopedWrite, self::VIA_RECORDS],
        'kumwe_business_update' => [McpRiskClass::ScopedWrite, self::VIA_RECORDS],
        'kumwe_business_archive' => [McpRiskClass::ScopedWrite, self::VIA_RECORDS],
        'kumwe_business_restore' => [McpRiskClass::ScopedWrite, self::VIA_RECORDS],
        'kumwe_business_delete' => [McpRiskClass::Destructive, self::VIA_RECORDS],
        'kumwe_business_relate' => [McpRiskClass::ScopedWrite, self::VIA_RECORDS],
        'kumwe_business_unrelate' => [McpRiskClass::ScopedWrite, self::VIA_RECORDS],
        'kumwe_business_reorder' => [McpRiskClass::ScopedWrite, self::VIA_RECORDS],
        'kumwe_business_request_action' => [McpRiskClass::ScopedWrite, self::VIA_RECORDS],
        'kumwe_business_execute_action' => [McpRiskClass::Destructive, self::VIA_RECORDS],
        'kumwe_business_operation_status' => [McpRiskClass::Read, self::VIA_RECORDS],
        'kumwe_business_definition_list' => [McpRiskClass::Read, self::VIA_DEFINITIONS],
        'kumwe_business_definition_get' => [McpRiskClass::Read, self::VIA_DEFINITIONS],
        'kumwe_business_definition_draft' => [McpRiskClass::Read, self::VIA_DEFINITIONS],
        'kumwe_business_definition_history' => [McpRiskClass::Read, self::VIA_DEFINITIONS],
        'kumwe_business_definition_compatibility' => [McpRiskClass::Read, self::VIA_DEFINITIONS],
        'kumwe_business_definition_publish' => [McpRiskClass::ScopedWrite, self::VIA_DEFINITIONS],
        'kumwe_business_schema_definitions' => [McpRiskClass::Read, self::VIA_SCHEMA],
        'kumwe_business_schema_plan_list' => [McpRiskClass::Read, self::VIA_SCHEMA],
        'kumwe_business_schema_plan_get' => [McpRiskClass::Read, self::VIA_SCHEMA],
        'kumwe_business_schema_plan_create' => [McpRiskClass::ScopedWrite, self::VIA_SCHEMA],
        'kumwe_business_schema_plan_approve' => [McpRiskClass::InstallationGlobal, self::VIA_SCHEMA],
        'kumwe_business_schema_plan_execute' => [McpRiskClass::InstallationGlobal, self::VIA_SCHEMA],
        'kumwe_business_schema_plan_recover' => [McpRiskClass::InstallationGlobal, self::VIA_SCHEMA],
        'kumwe_schedule_list' => [McpRiskClass::Read, self::VIA_AUTOMATION],
        'kumwe_job_list' => [McpRiskClass::Read, self::VIA_AUTOMATION],
        'kumwe_schedule_create' => [McpRiskClass::ScopedWrite, self::VIA_AUTOMATION],
        'kumwe_schedule_update' => [McpRiskClass::ScopedWrite, self::VIA_AUTOMATION],
        'kumwe_schedule_delete' => [McpRiskClass::Destructive, self::VIA_AUTOMATION],
        'kumwe_job_retry' => [McpRiskClass::ScopedWrite, self::VIA_AUTOMATION],
        'kumwe_job_cancel' => [McpRiskClass::Destructive, self::VIA_AUTOMATION],
        'kumwe_business_report_list' => [McpRiskClass::Read, self::VIA_REPORTING],
        'kumwe_business_report_execute' => [McpRiskClass::Read, self::VIA_REPORTING],
        'kumwe_business_report_export_request' => [McpRiskClass::ScopedWrite, self::VIA_REPORTING],
        'kumwe_business_report_export_status' => [McpRiskClass::Read, self::VIA_REPORTING],
        'kumwe_business_report_export_download' => [McpRiskClass::Read, self::VIA_REPORTING],
    ];

    /**
     * Route to the same surface for a caller who cannot or should not use the machine surface.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string VIA_DOCUMENTATION = 'The kumwe://capabilities resource, or docs/mcp.md.';

    /**
     * Non-MCP route for the content tools.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string VIA_CONTENT = 'Administrator console: Content, or bin/kumwe content.';

    /**
     * Non-MCP route for the navigation tools.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string VIA_NAVIGATION = 'Administrator console: Navigation, or bin/kumwe navigation.';

    /**
     * Non-MCP route for the site-settings tools.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string VIA_SETTINGS = 'Administrator console: Settings, or bin/kumwe settings.';

    /**
     * Non-MCP route for the user and role tools.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string VIA_IDENTITY = 'Administrator console: Users and roles, or bin/kumwe access.';

    /**
     * Non-MCP route for the access-token tools.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string VIA_TOKENS = 'Administrator console: Access tokens, bin/kumwe token:create, '
        . 'or bin/kumwe access for revocation.';

    /**
     * Non-MCP route for the extension trust-store tools.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string VIA_TRUST = 'Administrator console: Extension trust keys, or bin/kumwe extension:trust.';

    /**
     * Non-MCP route for the extension-lifecycle tools, including the step-up path this surface lacks.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string VIA_EXTENSIONS = 'Administrator console: Extensions, or the extension lifecycle CLI for '
        . 'non-administrator themes; administrator-theme changes need browser or REST step-up, while the console '
        . 'offers break-glass recovery to the built-in theme.';

    /**
     * Non-MCP route for the generated business-record tools.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string VIA_RECORDS = 'The generated record screens, the protected REST record API, '
        . 'or bin/kumwe business-record.';

    /**
     * Non-MCP route for the business-definition tools.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string VIA_DEFINITIONS = 'Administrator console: Business definitions, '
        . 'or bin/kumwe business-definition.';

    /**
     * Non-MCP route for the business-schema plan tools, including the stages this surface refuses.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string VIA_SCHEMA = 'Administrator console: Business schema plans, or bin/kumwe business-schema; '
        . 'purge planning and high-impact approval are browser or console only.';

    /**
     * Non-MCP route for the schedule and job tools.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string VIA_AUTOMATION = 'Administrator console: Automation, or bin/kumwe automation.';

    /**
     * Non-MCP route for the report and export tools.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string VIA_REPORTING = 'Administrator console: Reports, or the report and export actions '
        . 'of bin/kumwe business-record.';

    /**
     * List every tool this release publishes, in the order the server registers them.
     *
     * Each entry names the handler method that serves it, the capability that handler enforces, the
     * risk class that says what a successful call costs, and the non-MCP route to the same outcome.
     * A null capability means no single capability decides the call: `kumwe_discover` is open to any
     * authenticated caller, and `kumwe_content_transition` authorizes the specific transition it is
     * asked to perform, which is why only the two lowest risk classes may leave it unset. Mutating
     * entries always carry an `operationId` property so a retry deduplicates.
     *
     * @return  list<array{
     *            name: string, title: string, description: string, handler: string,
     *            capability: string|null, capabilityResolver: string|McpDynamicCapabilityResolver,
     *            mutationGuard: McpMutationGuardMode, readOnly: bool, destructive: bool, idempotent: bool,
     *            risk: McpRiskClass, alternative: string,
     *            inputSchema: array<string, mixed>, outputSchema: array<string, mixed>
     *          }>
     *
     * @throws  McpCatalogInvalid  When a declared tool carries no entry in the risk table.
     *
     * @since   2.0.0
     */
    public function tools(): array
    {
        $tools = [];
        foreach ($this->declarations() as $declaration) {
            $classification = self::RISK[$declaration['name']] ?? null;
            if ($classification === null) {
                throw new McpCatalogInvalid(sprintf(
                    'Tool "%s" is published without a declared risk class.',
                    $declaration['name'],
                ));
            }
            $tools[] = [...$declaration, 'risk' => $classification[0], 'alternative' => $classification[1]];
        }

        return $tools;
    }

    /**
     * Declare every tool's identity, annotations and schemas, before risk classification is merged in.
     *
     * Separated from `tools()` so that the classification table is the only place a risk class is
     * written down, and so a tool cannot be added here and quietly published unclassified.
     *
     * @return  list<array{
     *            name: string, title: string, description: string, handler: string,
     *            capability: string|null, capabilityResolver: string|McpDynamicCapabilityResolver,
     *            mutationGuard: McpMutationGuardMode, readOnly: bool, destructive: bool, idempotent: bool,
     *            inputSchema: array<string, mixed>, outputSchema: array<string, mixed>
     *          }>
     *
     * @since   2.0.0
     */
    private function declarations(): array
    {
        $object = ['type' => 'object', 'additionalProperties' => true];

        return [
            $this->tool(
                'kumwe_discover',
                'Discover Kumwe',
                'Discover the available Kumwe MCP surface.',
                'discover',
                McpDynamicCapabilityResolver::Authenticated,
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
                McpDynamicCapabilityResolver::ContentTransition,
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
                'Move content to recoverable trash.',
                'trashContent',
                'content.delete',
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
                $this->trustKeyProperties('keyId'),
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
                [...$this->trustKeyProperties('newKeyId'), 'oldKeyId' => ['type' => 'string']],
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
                'Activate an installed extension. Taking over the administrator surface is refused here.',
                'activateExtension',
                'extensions.manage',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(),
                    'identifier' => ['type' => 'string'],
                    'surface' => ['type' => ['string', 'null'], 'enum' => ['site', 'administrator', null]],
                ],
                $object,
                ['operationId', 'identifier']
            ),
            $this->tool(
                'kumwe_extension_disable',
                'Disable extension',
                'Disable an installed extension. Disabling the live administrator theme is refused here.',
                'disableExtension',
                'extensions.manage',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(),
                    'identifier' => ['type' => 'string'],
                ],
                $object,
                ['operationId', 'identifier']
            ),
            $this->tool(
                'kumwe_extension_uninstall',
                'Uninstall extension',
                'Uninstall an extension. Removing the live administrator theme is refused here.',
                'uninstallExtension',
                'extensions.manage',
                false,
                true,
                true,
                [
                    'operationId' => $this->operationId(),
                    'identifier' => ['type' => 'string'],
                ],
                $object,
                ['operationId', 'identifier']
            ),
            $this->tool(
                'kumwe_business_discover',
                'Discover generated business entities',
                'Discover policy-visible generated business entities, fields, views, actions, and relationships.',
                'discoverBusinessRecords',
                'business.record.browse',
                true,
                false,
                true,
                [],
                $this->closedObject([
                    'items' => ['type' => 'array', 'maxItems' => 200, 'items' => $this->businessMetadata()],
                    'truncated' => ['type' => 'boolean'],
                ], ['items', 'truncated'])
            ),
            $this->tool(
                'kumwe_business_inspect',
                'Inspect a generated business entity',
                'Inspect one policy-visible generated entity schema and its typed contributions.',
                'inspectBusinessRecord',
                'business.record.read',
                true,
                false,
                true,
                ['definition' => $this->businessDefinitionIdentifier()],
                $this->closedObject(['definition' => $this->businessMetadata()], ['definition']),
                ['definition']
            ),
            $this->tool(
                'kumwe_business_view',
                'Execute a custom business view',
                'Execute one policy-visible typed custom view through its signed bounded contract.',
                'executeBusinessView',
                McpDynamicCapabilityResolver::BusinessView,
                true,
                false,
                true,
                [
                    'definition' => $this->businessDefinitionIdentifier(),
                    'view' => $this->businessHandle(),
                    'query' => $this->businessQuery(),
                    'parameters' => $this->businessValues(true),
                    'record' => $this->nullable($this->businessRecordIdentifier()),
                ],
                $this->closedObject([
                    'definition' => $this->businessMetadata(),
                    'available_operations' => [
                        'type' => 'object',
                        'maxProperties' => 20,
                        'additionalProperties' => ['type' => 'boolean'],
                    ],
                    'view' => ['type' => 'object', 'maxProperties' => 16, 'additionalProperties' => true],
                    'data' => ['type' => 'object', 'maxProperties' => 128, 'additionalProperties' => true],
                ], ['definition', 'available_operations', 'view', 'data']),
                ['definition', 'view']
            ),
            $this->tool(
                'kumwe_business_search',
                'Search generated business records',
                'Run one bounded policy-filtered query against a generated business entity.',
                'searchBusinessRecords',
                'business.record.browse',
                true,
                false,
                true,
                [
                    'definition' => $this->businessDefinitionIdentifier(),
                    'query' => $this->businessQuery(),
                ],
                $this->businessSearchOutput(),
                ['definition']
            ),
            $this->tool(
                'kumwe_business_read',
                'Read a generated business record',
                'Read one policy-visible generated business record by its public identity.',
                'readBusinessRecord',
                'business.record.read',
                true,
                false,
                true,
                [
                    'definition' => $this->businessDefinitionIdentifier(),
                    'record' => $this->businessRecordIdentifier(),
                    'includeArchived' => ['type' => 'boolean'],
                    'includeDeleted' => ['type' => 'boolean'],
                ],
                $this->businessReadOutput(),
                ['definition', 'record']
            ),
            $this->tool(
                'kumwe_business_history',
                'Read generated business record history',
                'Read one bounded policy-filtered revision page for a generated business record.',
                'businessRecordHistory',
                'business.record.history',
                true,
                false,
                true,
                [
                    'definition' => $this->businessDefinitionIdentifier(),
                    'record' => $this->businessRecordIdentifier(),
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                    'beforeVersion' => ['type' => ['integer', 'null'], 'minimum' => 1],
                ],
                $this->businessHistoryOutput(),
                ['definition', 'record']
            ),
            $this->tool(
                'kumwe_business_plan_mutation',
                'Plan a generated business mutation',
                'Bind one exact mutation to current definition, runtime, policy, actor, and record state.',
                'planBusinessRecordMutation',
                McpDynamicCapabilityResolver::BusinessMutationPlan,
                true,
                false,
                true,
                [
                    'operationId' => $this->operationId(),
                    'operation' => $this->businessMutationOperation(),
                    'definition' => $this->businessDefinitionIdentifier(),
                    'record' => $this->nullable($this->businessRecordIdentifier()),
                    'expectedVersion' => $this->nullable(['type' => 'integer', 'minimum' => 1]),
                    'values' => $this->businessValues(true),
                    'relationship' => $this->nullable($this->businessHandle()),
                    'target' => $this->nullable($this->businessRecordIdentifier()),
                    'position' => [
                        'type' => ['integer', 'null'],
                        'minimum' => 0,
                        'maximum' => 1_000_000,
                    ],
                    'targetValues' => $this->businessValues(true),
                    'orderedRecordIds' => [
                        'type' => 'array',
                        'maxItems' => 1000,
                        'uniqueItems' => true,
                        'items' => $this->businessRecordIdentifier(),
                    ],
                    'action' => $this->nullable($this->businessHandle()),
                    'input' => $this->businessValues(true),
                    'approvalRequestId' => $this->nullable(['type' => 'string', 'format' => 'uuid']),
                ],
                $this->businessMutationPlanOutput(),
                ['operationId', 'operation', 'definition']
            ),
            $this->tool(
                'kumwe_business_create',
                'Create a generated business record',
                'Execute a planned typed record create under a replay-safe operation identity.',
                'createBusinessRecord',
                'business.record.create',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(),
                    'plan' => $this->businessPlan(),
                    'definition' => $this->businessDefinitionIdentifier(),
                    'values' => $this->businessValues(false),
                    'record' => $this->businessRecordIdentifier(),
                ],
                $this->businessMutationOutput(),
                ['operationId', 'plan', 'definition', 'values'],
                McpMutationGuardMode::BusinessDelegate,
            ),
            $this->tool(
                'kumwe_business_update',
                'Update a generated business record',
                'Update one typed generated record at the exact version previously read.',
                'updateBusinessRecord',
                'business.record.update',
                false,
                false,
                true,
                [
                    ...$this->businessVersionedRecordProperties(),
                    'values' => $this->businessValues(false),
                ],
                $this->businessMutationOutput(),
                ['operationId', 'plan', 'definition', 'record', 'expectedVersion', 'values'],
                McpMutationGuardMode::BusinessDelegate,
            ),
            $this->tool(
                'kumwe_business_archive',
                'Archive a generated business record',
                'Archive one generated record at an exact optimistic version.',
                'archiveBusinessRecord',
                'business.record.archive',
                false,
                false,
                true,
                $this->businessVersionedRecordProperties(),
                $this->businessMutationOutput(),
                ['operationId', 'plan', 'definition', 'record', 'expectedVersion'],
                McpMutationGuardMode::BusinessDelegate,
            ),
            $this->tool(
                'kumwe_business_restore',
                'Restore a generated business record',
                'Restore one archived or soft-deleted generated record at an exact version.',
                'restoreBusinessRecord',
                'business.record.restore',
                false,
                false,
                true,
                $this->businessVersionedRecordProperties(),
                $this->businessMutationOutput(),
                ['operationId', 'plan', 'definition', 'record', 'expectedVersion'],
                McpMutationGuardMode::BusinessDelegate,
            ),
            $this->tool(
                'kumwe_business_delete',
                'Delete a generated business record',
                'Delete one generated record at an exact optimistic version.',
                'deleteBusinessRecord',
                'business.record.delete',
                false,
                true,
                true,
                $this->businessVersionedRecordProperties(),
                $this->businessMutationOutput(),
                ['operationId', 'plan', 'definition', 'record', 'expectedVersion'],
                McpMutationGuardMode::BusinessDelegate,
            ),
            $this->tool(
                'kumwe_business_relate',
                'Relate generated business records',
                'Create one declared relationship link or owned line at an exact source version.',
                'relateBusinessRecords',
                'business.record.relate',
                false,
                false,
                true,
                [
                    ...$this->businessVersionedRecordProperties(),
                    'relationship' => $this->businessHandle(),
                    'target' => $this->businessRecordIdentifier(),
                    'position' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 1_000_000],
                    'targetValues' => $this->businessValues(true),
                ],
                $this->businessMutationOutput(),
                ['operationId', 'plan', 'definition', 'record', 'expectedVersion', 'relationship', 'target'],
                McpMutationGuardMode::BusinessDelegate,
            ),
            $this->tool(
                'kumwe_business_unrelate',
                'Unrelate generated business records',
                'Remove one declared relationship link at an exact source version.',
                'unrelateBusinessRecords',
                'business.record.relate',
                false,
                false,
                true,
                [
                    ...$this->businessVersionedRecordProperties(),
                    'relationship' => $this->businessHandle(),
                    'target' => $this->businessRecordIdentifier(),
                ],
                $this->businessMutationOutput(),
                ['operationId', 'plan', 'definition', 'record', 'expectedVersion', 'relationship', 'target'],
                McpMutationGuardMode::BusinessDelegate,
            ),
            $this->tool(
                'kumwe_business_reorder',
                'Reorder generated business records',
                'Replace the complete order of one declared relationship at an exact source version.',
                'reorderBusinessRecords',
                'business.record.relate',
                false,
                false,
                true,
                [
                    ...$this->businessVersionedRecordProperties(),
                    'relationship' => $this->businessHandle(),
                    'orderedRecordIds' => [
                        'type' => 'array',
                        'maxItems' => 1000,
                        'uniqueItems' => true,
                        'items' => $this->businessRecordIdentifier(),
                    ],
                ],
                $this->businessMutationOutput(),
                [
                    'operationId', 'plan', 'definition', 'record', 'expectedVersion',
                    'relationship', 'orderedRecordIds',
                ],
                McpMutationGuardMode::BusinessDelegate,
            ),
            $this->tool(
                'kumwe_business_request_action',
                'Request a generated business action',
                'Request independent maker-checker approval for one exact high-impact action attempt.',
                'requestBusinessRecordAction',
                'business.record.action',
                false,
                false,
                true,
                [
                    ...$this->businessVersionedRecordProperties(),
                    'action' => $this->businessHandle(),
                    'input' => $this->businessValues(true),
                ],
                $this->closedObject([
                    'approval_request_id' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                ], ['approval_request_id']),
                ['operationId', 'plan', 'definition', 'record', 'expectedVersion', 'action'],
                McpMutationGuardMode::BusinessDelegate,
            ),
            $this->tool(
                'kumwe_business_execute_action',
                'Execute a generated business action',
                'Execute one ordinary declared action; high-impact consumption requires a browser step-up session.',
                'executeBusinessRecordAction',
                'business.record.action',
                false,
                true,
                true,
                [
                    ...$this->businessVersionedRecordProperties(),
                    'action' => $this->businessHandle(),
                    'input' => $this->businessValues(true),
                    'approvalRequestId' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                ],
                $this->businessMutationOutput(),
                ['operationId', 'plan', 'definition', 'record', 'expectedVersion', 'action'],
                McpMutationGuardMode::BusinessDelegate,
            ),
            $this->tool(
                'kumwe_business_operation_status',
                'Inspect a generated business operation',
                'Inspect one caller-, policy-, and credential-bound generated-business mutation.',
                'businessRecordOperationStatus',
                'business.record.read',
                true,
                false,
                true,
                ['operationId' => $this->operationId()],
                $this->businessStatusOutput(),
                ['operationId']
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
                    'operationId' => $this->operationId(),
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
                ['operationId' => $this->operationId(), 'definitionId' => ['type' => 'string']],
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
                    'operationId' => $this->operationId(),
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
                ['operationId' => $this->operationId(), 'planId' => ['type' => 'string']],
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
                ['operationId' => $this->operationId(), 'planId' => ['type' => 'string']],
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
            $this->tool(
                'kumwe_business_report_list',
                'List business reports',
                'List active contributed reports visible to this credential.',
                'listBusinessReports',
                'business.record.report',
                true,
                false,
                true,
                [],
                $this->reportCollectionOutput(),
            ),
            $this->tool(
                'kumwe_business_report_execute',
                'Execute business report',
                'Execute one active policy-filtered contributed report with bounded parameters.',
                'executeBusinessReport',
                'business.record.report',
                true,
                false,
                true,
                [
                    'report' => $this->reportIdentifier(),
                    'parameters' => $this->reportParameters(),
                ],
                $this->reportResultOutput(),
                ['report'],
            ),
            $this->tool(
                'kumwe_business_report_export_request',
                'Request business report export',
                'Idempotently queue one policy-bound CSV report export.',
                'requestBusinessReportExport',
                'business.record.export',
                false,
                false,
                true,
                [
                    'operationId' => $this->operationId(),
                    'report' => $this->reportIdentifier(),
                    'parameters' => $this->reportParameters(),
                    'retentionSeconds' => ['type' => 'integer', 'minimum' => 60, 'maximum' => 604_800],
                ],
                $this->reportExportOutput(),
                ['operationId', 'report'],
            ),
            $this->tool(
                'kumwe_business_report_export_status',
                'Read business report export status',
                'Read current policy-bound export lifecycle metadata.',
                'businessReportExportStatus',
                'business.record.export',
                true,
                false,
                true,
                ['artifact' => ['type' => 'string', 'format' => 'uuid']],
                $this->reportExportOutput(),
                ['artifact'],
            ),
            $this->tool(
                'kumwe_business_report_export_download',
                'Download business report export',
                'Download a verified completed CSV export up to one megabyte as base64.',
                'downloadBusinessReportExport',
                'business.record.export',
                true,
                false,
                true,
                ['artifact' => ['type' => 'string', 'format' => 'uuid']],
                $this->reportDownloadOutput(),
                ['artifact'],
            ),
        ];
    }

    /**
     * List the readable resources this release publishes.
     *
     * The one entry serves `kumwe://capabilities` as JSON from `capabilityResource`, which hands a
     * client the same summary the discovery tool returns without spending a tool call to get it.
     *
     * @return  list<array{
     *              uri: string, name: string, title: string, description: string,
     *              mimeType: string, handler: string
     *          }>  One entry per resource in registration order.
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
     * @return  list<array{
     *              name: string, title: string, description: string, handler: string
     *          }>  One entry per prompt in registration order.
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
     * Summarise the public surface and the policy metadata an MCP client needs to call it safely.
     *
     * Discovery is available to every authenticated caller, so the summary excludes schemas, handler
     * methods and caller state. Capability requirements are not secret, however: publishing them beside
     * each risk class and non-MCP alternative lets a client request the least authority and route a
     * refused or unsuitable operation without reverse-engineering the server implementation.
     *
     * @return  array{
     *              product: string, mode: string, tools: list<string>, resources: list<string>,
     *              prompts: list<string>, tool_metadata: list<array{
     *                  name: string, capability: string|null, risk: string, alternative: string
     *              }>
     *          }  Public surface identity and per-tool policy metadata in catalogue order.
     *
     * @since   2.0.0
     */
    public function publicSummary(): array
    {
        $names = [];
        $metadata = [];
        foreach ($this->tools() as $tool) {
            $names[] = $tool['name'];
            $metadata[] = [
                'name' => $tool['name'],
                'capability' => $tool['capability'],
                'risk' => $tool['risk']->value,
                'alternative' => $tool['alternative'],
            ];
        }

        return [
            'product' => 'Kumwe App',
            'mode' => 'capability_protected_read_write',
            'tools' => $names,
            'resources' => array_column($this->resources(), 'uri'),
            'prompts' => array_column($this->prompts(), 'name'),
            'tool_metadata' => $metadata,
        ];
    }

    /**
     * Assemble one catalogue entry from its identity, its annotation hints and its schema fragments.
     *
     * The input schema is always a closed object — `additionalProperties` is false — so an argument no
     * property names is rejected by the server before a handler is reached.
     *
     * @param string $name Tool name a client calls, stable for the release.
     * @param   string                               $title               Short label, reused as the annotation title.
     * @param string $description One line telling a client what the tool is for.
     * @param string $handler Method on `KumweMcpHandlers` this tool is bound to.
     * @param   string|McpDynamicCapabilityResolver  $capabilityResolver  Literal capability the handler
     *          requires, or the closed dynamic resolver its live implementation enforces.
     * @param bool $readOnly True when the tool only reads; false marks a mutation.
     * @param bool $destructive True when a successful call removes or overwrites state
     *          the caller cannot simply rebuild, which clients may use to prompt for confirmation.
     * @param bool $idempotent True when repeating the call with the same arguments
     *          leaves the same end state.
     * @param array<string, mixed> $properties JSON Schema property map of the tool's input object.
     * @param array<string, mixed> $output JSON Schema published as the tool's output schema.
     * @param   list<string>                         $required            Input property names a client must supply.
     * @param ?McpMutationGuardMode $mutationGuard Explicit non-local guard route, or null to select
     *          no guard for a read and the local handler graph for a mutation.
     *
     * @return  array{
     *            name: string, title: string, description: string, handler: string,
     *            capability: string|null, capabilityResolver: string|McpDynamicCapabilityResolver,
     *            mutationGuard: McpMutationGuardMode, readOnly: bool, destructive: bool, idempotent: bool,
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
        string|McpDynamicCapabilityResolver $capabilityResolver,
        bool $readOnly,
        bool $destructive,
        bool $idempotent,
        array $properties,
        array $output,
        array $required = [],
        ?McpMutationGuardMode $mutationGuard = null,
    ): array {
        $capability = is_string($capabilityResolver) ? $capabilityResolver : null;
        $mutationGuard ??= $readOnly ? McpMutationGuardMode::None : McpMutationGuardMode::Local;

        return [
            'name' => $name, 'title' => $title, 'description' => $description, 'handler' => $handler,
            'capability' => $capability, 'capabilityResolver' => $capabilityResolver,
            'mutationGuard' => $mutationGuard, 'readOnly' => $readOnly, 'destructive' => $destructive,
            'idempotent' => $idempotent,
            'inputSchema' => [
                'type' => 'object', 'properties' => $properties, 'required' => $required,
                'additionalProperties' => false,
            ],
            'outputSchema' => $output,
        ];
    }

    /**
     * Build a closed JSON object schema with an explicit member vocabulary.
     *
     * @param   array<string, mixed>  $properties  Complete property schemas keyed by wire name.
     * @param   list<string>          $required    Members that must be present.
     *
     * @return  array<string, mixed>  Closed JSON object schema.
     *
     * @since   2.0.0
     */
    private function closedObject(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * Describe a bounded namespaced report identifier.
     *
     * @return  array<string, mixed>  JSON Schema for report identifiers.
     *
     * @since   2.0.0
     */
    private function reportIdentifier(): array
    {
        return [
            'type' => 'string',
            'minLength' => 3,
            'maxLength' => 191,
            'pattern' => '^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$',
        ];
    }

    /**
     * Describe the closed scalar or scalar-list parameter object reports accept.
     *
     * @return  array<string, mixed>  Bounded report parameter JSON Schema.
     *
     * @since   2.0.0
     */
    private function reportParameters(): array
    {
        return [
            'type' => 'object',
            'maxProperties' => 32,
            'propertyNames' => ['pattern' => '^[a-z][a-z0-9_]{0,62}$'],
            'additionalProperties' => [
                'oneOf' => [
                    ['type' => 'string', 'maxLength' => 4096],
                    ['type' => 'integer'],
                    ['type' => 'boolean'],
                    ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => [
                        'oneOf' => [
                            ['type' => 'string', 'maxLength' => 4096],
                            ['type' => 'integer'],
                            ['type' => 'boolean'],
                        ],
                    ]],
                ],
            ],
        ];
    }

    /**
     * Describe a synchronous report result with scalar rows and typed columns.
     *
     * @return  array<string, mixed>  Closed bounded report result JSON Schema.
     *
     * @since   2.0.0
     */
    private function reportResultOutput(): array
    {
        $scalar = ['oneOf' => [
            ['type' => 'string', 'maxLength' => 65_536],
            ['type' => 'integer'],
            ['type' => 'boolean'],
            ['type' => 'null'],
        ]];

        return $this->closedObject([
            'report' => $this->reportIdentifier(),
            'definition_checksum' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            'query_digest' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            'columns' => ['type' => 'array', 'maxItems' => 96, 'items' => $this->closedObject([
                'alias' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,62}$'],
                'label' => ['type' => 'string', 'maxLength' => 191],
                'type' => [
                    'type' => 'string',
                    'enum' => ['string', 'integer', 'decimal', 'boolean', 'date', 'date_time', 'identifier'],
                ],
            ], ['alias', 'label', 'type'])],
            'rows' => ['type' => 'array', 'maxItems' => 1000, 'items' => [
                'type' => 'object', 'maxProperties' => 96, 'additionalProperties' => $scalar,
            ]],
            'row_count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000],
        ], ['report', 'definition_checksum', 'query_digest', 'columns', 'rows', 'row_count']);
    }

    /**
     * Describe the active report discovery collection.
     *
     * @return  array<string, mixed>  Closed bounded report collection JSON Schema.
     *
     * @since   2.0.0
     */
    private function reportCollectionOutput(): array
    {
        $parameter = $this->closedObject([
            'name' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,62}$'],
            'type' => [
                'type' => 'string',
                'enum' => ['string', 'integer', 'decimal', 'boolean', 'date', 'date_time', 'identifier'],
            ],
            'required' => ['type' => 'boolean'],
            'multiple' => ['type' => 'boolean'],
            'default' => [
                'oneOf' => [
                    ['type' => 'string', 'maxLength' => 4096],
                    ['type' => 'integer'],
                    ['type' => 'boolean'],
                    ['type' => 'array', 'maxItems' => 100, 'items' => [
                        'oneOf' => [
                            ['type' => 'string', 'maxLength' => 4096],
                            ['type' => 'integer'],
                            ['type' => 'boolean'],
                        ],
                    ]],
                    ['type' => 'null'],
                ],
            ],
        ], ['name', 'type', 'required', 'multiple', 'default']);
        $report = $this->closedObject([
            'id' => $this->reportIdentifier(),
            'title' => ['type' => 'string', 'maxLength' => 191],
            'parameters' => ['type' => 'array', 'maxItems' => 32, 'items' => $parameter],
        ], ['id', 'title', 'parameters']);

        return $this->closedObject([
            'items' => ['type' => 'array', 'maxItems' => 256, 'items' => $report],
        ], ['items']);
    }

    /**
     * Describe omission-safe queued export lifecycle metadata.
     *
     * @return  array<string, mixed>  Closed export status JSON Schema.
     *
     * @since   2.0.0
     */
    private function reportExportOutput(): array
    {
        $nullableDate = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $nullableDigest = ['type' => ['string', 'null'], 'pattern' => '^[a-f0-9]{64}$'];

        return $this->closedObject([
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'report' => $this->reportIdentifier(),
            'status' => ['type' => 'string', 'enum' => ['queued', 'running', 'completed', 'failed']],
            'created_at' => ['type' => 'string', 'format' => 'date-time'],
            'expires_at' => ['type' => 'string', 'format' => 'date-time'],
            'started_at' => $nullableDate,
            'completed_at' => $nullableDate,
            'filename' => ['type' => ['string', 'null'], 'maxLength' => 127],
            'size' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'row_count' => ['type' => ['integer', 'null'], 'minimum' => 0],
            'checksum' => $nullableDigest,
            'failure_code' => ['type' => ['string', 'null'], 'maxLength' => 63],
            'version' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 16],
        ], [
            'id', 'report', 'status', 'created_at', 'expires_at', 'started_at', 'completed_at',
            'filename', 'size', 'row_count', 'checksum', 'failure_code', 'version',
        ]);
    }

    /**
     * Describe the one-megabyte verified base64 download result.
     *
     * @return  array<string, mixed>  Closed bounded MCP download JSON Schema.
     *
     * @since   2.0.0
     */
    private function reportDownloadOutput(): array
    {
        return $this->closedObject([
            'filename' => ['type' => 'string', 'maxLength' => 127],
            'size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1_048_576],
            'checksum' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            'encoding' => ['type' => 'string', 'const' => 'base64'],
            'content' => ['type' => 'string', 'maxLength' => 1_398_104, 'contentEncoding' => 'base64'],
        ], ['filename', 'size', 'checksum', 'encoding', 'content']);
    }

    /**
     * Describe a definition UUID or namespaced handle accepted by the shared resolver.
     *
     * @return  array<string, int|string>  Bounded definition identifier schema.
     *
     * @since   2.0.0
     */
    private function businessDefinitionIdentifier(): array
    {
        return [
            'type' => 'string',
            'minLength' => 3,
            'maxLength' => 191,
            'pattern' => '^(?:[0-9a-fA-F-]{36}|[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+)$',
        ];
    }

    /**
     * Describe one public business-record identity without accepting control characters.
     *
     * @return  array<string, int|string>  Bounded public identity schema.
     *
     * @since   2.0.0
     */
    private function businessRecordIdentifier(): array
    {
        return [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 191,
            'pattern' => '^[^\\u0000-\\u001F\\u007F]+$',
        ];
    }

    /**
     * Describe a definition field, relation, action, projection, or alias handle.
     *
     * @return  array<string, int|string>  Lowercase bounded handle schema.
     *
     * @since   2.0.0
     */
    private function businessHandle(): array
    {
        return [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 63,
            'pattern' => '^[a-z][a-z0-9_]{0,62}$',
        ];
    }

    /**
     * Describe the closed mutation vocabulary accepted by the planning tool.
     *
     * @return  array<string, mixed>  Exact generated-business mutation-name schema.
     *
     * @since   2.0.0
     */
    private function businessMutationOperation(): array
    {
        return [
            'type' => 'string',
            'enum' => [
                'create', 'update', 'archive', 'restore', 'delete', 'relate', 'unrelate', 'reorder',
                'request_action', 'execute_action',
            ],
        ];
    }

    /**
     * Describe an opaque signed generated-business mutation plan.
     *
     * @return  array<string, int|string>  Versioned, bounded signed plan schema.
     *
     * @since   2.0.0
     */
    private function businessPlan(): array
    {
        return [
            'type' => 'string',
            'minLength' => 128,
            'maxLength' => 4096,
            'pattern' => '^v2\\.[A-Za-z0-9_-]+$',
        ];
    }

    /**
     * Permit an explicit JSON null in an otherwise unchanged schema.
     *
     * @param   array<string, mixed>  $schema  Original non-null schema.
     *
     * @return  array<string, mixed>  A union of the original schema and null.
     *
     * @since   2.0.0
     */
    private function nullable(array $schema): array
    {
        return ['anyOf' => [$schema, ['type' => 'null']]];
    }

    /**
     * Describe a dynamic but bounded map of values keyed by declared definition handles.
     *
     * Definition metadata supplies exact per-field schemas at runtime. The static envelope caps field
     * count and property names; shared value guards enforce depth, node count, exact decimals and types.
     *
     * @param   bool  $allowEmpty  Whether an empty map is valid for this use.
     *
     * @return  array<string, mixed>  Bounded dynamic field-value object schema.
     *
     * @since   2.0.0
     */
    private function businessValues(bool $allowEmpty): array
    {
        return [
            'type' => 'object',
            'minProperties' => $allowEmpty ? 0 : 1,
            'maxProperties' => 256,
            'propertyNames' => $this->businessHandle(),
            'additionalProperties' => true,
        ];
    }

    /**
     * Return the common input properties every existing-record mutation must carry.
     *
     * @return  array<string, array<string, mixed>>  Operation, definition, record and version schemas.
     *
     * @since   2.0.0
     */
    private function businessVersionedRecordProperties(): array
    {
        return [
            'operationId' => $this->operationId(),
            'plan' => $this->businessPlan(),
            'definition' => $this->businessDefinitionIdentifier(),
            'record' => $this->businessRecordIdentifier(),
            'expectedVersion' => ['type' => 'integer', 'minimum' => 1],
        ];
    }

    /**
     * Describe a nested filter node, whose membership JSON Schema alone cannot close.
     *
     * A filter is a tree of nodes that all share one property vocabulary, and this dialect publishes no
     * reference mechanism to say so, which is why the nested node states its membership decision as
     * open rather than pretending to a closure it cannot express. The opening is bounded twice over:
     * `maxProperties` caps the node at the thirteen members its parent declares, and
     * `BusinessRecordQueryFactory` compiles the whole tree against the definition's own field, operator
     * and relationship vocabulary, refusing anything it does not recognise. Stating the decision here
     * is what keeps `McpCatalogValidator` able to fail an object nobody decided about.
     *
     * @return  array<string, bool|int|string>  Bounded object schema for one nested filter node.
     *
     * @since   2.0.0
     */
    private function recursiveFilterNode(): array
    {
        return ['type' => 'object', 'maxProperties' => 13, 'additionalProperties' => true];
    }

    /**
     * Describe the closed bounded query document compiled by `BusinessRecordQueryFactory`.
     *
     * @return  array<string, mixed>  Query, filter, search, sort, cursor and projection schema.
     *
     * @since   2.0.0
     */
    private function businessQuery(): array
    {
        $stringList = static fn (int $maximum): array => [
            'type' => 'array',
            'maxItems' => $maximum,
            'uniqueItems' => true,
            'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 63],
        ];
        $filter = $this->closedObject([
            'type' => [
                'type' => 'string',
                'enum' => ['comparison', 'text', 'set', 'null', 'boolean', 'relation'],
            ],
            'field' => $this->businessHandle(),
            'operator' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 16],
            'value' => [],
            'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4096],
            'values' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => []],
            'negated' => ['type' => 'boolean'],
            'is_null' => ['type' => 'boolean'],
            'children' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 16,
                'items' => $this->recursiveFilterNode(),
            ],
            'relationship' => $this->businessHandle(),
            'quantifier' => ['type' => 'string', 'enum' => ['any', 'all', 'none']],
            'target' => $this->recursiveFilterNode(),
        ]);

        return $this->closedObject([
            'filter' => $filter,
            'search' => $this->closedObject([
                'term' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                'fields' => $stringList(16),
            ], ['term', 'fields']),
            'sorts' => [
                'type' => 'array',
                'maxItems' => 5,
                'items' => $this->closedObject([
                    'field' => $this->businessHandle(),
                    'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                    'nulls_last' => ['type' => 'boolean'],
                ], ['field']),
            ],
            'after' => ['type' => ['string', 'null'], 'maxLength' => 65_536],
            'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
            'projection' => $this->closedObject([
                'fields' => $stringList(64),
                'includes' => $stringList(4),
                'aggregates' => [
                    'type' => 'array',
                    'maxItems' => 16,
                    'items' => $this->closedObject([
                        'alias' => $this->businessHandle(),
                        'function' => [
                            'type' => 'string',
                            'enum' => ['count', 'sum', 'min', 'max', 'avg'],
                        ],
                        'field' => ['type' => ['string', 'null'], 'maxLength' => 63],
                    ], ['alias', 'function']),
                ],
            ]),
            'include_archived' => ['type' => 'boolean'],
            'include_deleted' => ['type' => 'boolean'],
        ]);
    }

    /**
     * Describe one policy-filtered generated entity document.
     *
     * @return  array<string, mixed>  Closed stable metadata envelope with bounded contribution lists.
     *
     * @since   2.0.0
     */
    private function businessMetadata(): array
    {
        $list = static fn (int $maximum): array => [
            'type' => 'array',
            'maxItems' => $maximum,
            'items' => ['type' => 'object', 'additionalProperties' => true],
        ];

        return $this->closedObject([
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'handle' => ['type' => 'string'],
            'singular_label' => ['type' => 'string'],
            'plural_label' => ['type' => 'string'],
            'version' => ['type' => 'integer', 'minimum' => 1],
            'checksum' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            'owner' => $this->closedObject([
                'type' => ['type' => 'string'],
                'identifier' => ['type' => 'string'],
            ], ['type', 'identifier']),
            'scope' => ['type' => 'string'],
            'soft_delete' => ['type' => 'boolean'],
            'workflow' => ['type' => ['object', 'null'], 'additionalProperties' => true],
            'operation' => ['type' => 'string'],
            'fields' => $list(256),
            'views' => $list(128),
            'actions' => $list(128),
            'relationships' => $list(256),
        ], [
            'id', 'handle', 'singular_label', 'plural_label', 'version', 'checksum', 'owner', 'scope',
            'soft_delete', 'workflow', 'operation', 'fields', 'views', 'actions', 'relationships',
        ]);
    }

    /**
     * Describe one omission-safe projected business record.
     *
     * @param   bool  $withFields  Whether semantic presentation fields join the browse item.
     *
     * @return  array<string, mixed>  Closed public record schema with no internal record key.
     *
     * @since   2.0.0
     */
    private function businessRecord(bool $withFields = false): array
    {
        $properties = [
            'definition_version' => ['type' => 'integer', 'minimum' => 1],
            'record_id' => $this->businessRecordIdentifier(),
            'version' => ['type' => 'integer', 'minimum' => 1],
            'workflow_state' => ['type' => ['string', 'null']],
            'values' => ['type' => 'object', 'additionalProperties' => true],
            'created_at' => ['type' => 'string', 'format' => 'date-time'],
            'updated_at' => ['type' => 'string', 'format' => 'date-time'],
            'archived_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'deleted_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'includes' => ['type' => 'object', 'additionalProperties' => true],
        ];
        if ($withFields) {
            $properties['fields'] = [
                'type' => 'array',
                'maxItems' => 256,
                'items' => ['type' => 'object', 'additionalProperties' => true],
            ];
        }

        return $this->closedObject($properties, array_keys($properties));
    }

    /**
     * Describe one bounded generated-business search result.
     *
     * @return  array<string, mixed>  Closed metadata, records, cursor, and aggregate schema.
     *
     * @since   2.0.0
     */
    private function businessSearchOutput(): array
    {
        return $this->closedObject([
            'definition' => $this->businessMetadata(),
            'available_operations' => ['type' => 'object', 'additionalProperties' => ['type' => 'boolean']],
            'items' => ['type' => 'array', 'maxItems' => 200, 'items' => $this->businessRecord(true)],
            'next_cursor' => ['type' => ['string', 'null'], 'maxLength' => 65_536],
            'aggregates' => ['type' => 'object', 'maxProperties' => 16, 'additionalProperties' => true],
        ], ['definition', 'available_operations', 'items', 'next_cursor', 'aggregates']);
    }

    /**
     * Describe one generated-business detail result.
     *
     * @return  array<string, mixed>  Closed metadata, record, and semantic field schema.
     *
     * @since   2.0.0
     */
    private function businessReadOutput(): array
    {
        return $this->closedObject([
            'definition' => $this->businessMetadata(),
            'available_operations' => ['type' => 'object', 'additionalProperties' => ['type' => 'boolean']],
            'record' => $this->businessRecord(),
            'fields' => [
                'type' => 'array',
                'maxItems' => 256,
                'items' => ['type' => 'object', 'additionalProperties' => true],
            ],
        ], ['definition', 'available_operations', 'record', 'fields']);
    }

    /**
     * Describe one bounded, omission-safe generated-business revision page.
     *
     * @return  array<string, mixed>  Closed history page and revision schema.
     *
     * @since   2.0.0
     */
    private function businessHistoryOutput(): array
    {
        $revision = $this->closedObject([
            'definition_version' => ['type' => 'integer', 'minimum' => 1],
            'record_version' => ['type' => 'integer', 'minimum' => 1],
            'revision_number' => ['type' => 'integer', 'minimum' => 1],
            'operation' => [
                'type' => 'string',
                'maxLength' => 96,
                'pattern' => '^[a-z][a-z0-9._:-]{0,95}$',
            ],
            'snapshot' => $this->businessValues(true),
            'changed_fields' => [
                'type' => 'array',
                'maxItems' => 256,
                'uniqueItems' => true,
                'items' => $this->businessHandle(),
            ],
            'occurred_at' => ['type' => 'string', 'format' => 'date-time'],
        ], [
            'definition_version',
            'record_version',
            'revision_number',
            'operation',
            'snapshot',
            'changed_fields',
            'occurred_at',
        ]);

        return $this->closedObject([
            'items' => ['type' => 'array', 'maxItems' => 200, 'items' => $revision],
            'has_more' => ['type' => 'boolean'],
            'next_before_version' => ['type' => ['integer', 'null'], 'minimum' => 1],
        ], ['items', 'has_more', 'next_before_version']);
    }

    /**
     * Describe the common omission-safe mutation result.
     *
     * @return  array<string, mixed>  Closed result schema with no internal record key.
     *
     * @since   2.0.0
     */
    private function businessMutationOutput(): array
    {
        return $this->closedObject([
            'definition_version' => ['type' => 'integer', 'minimum' => 1],
            'record_id' => $this->businessRecordIdentifier(),
            'version' => ['type' => 'integer', 'minimum' => 1],
            'workflow_state' => ['type' => ['string', 'null']],
            'operation' => ['type' => 'string'],
            'deleted' => ['type' => 'boolean'],
            'replayed' => ['type' => 'boolean'],
            'result' => ['type' => 'object', 'maxProperties' => 128, 'additionalProperties' => true],
        ], [
            'definition_version', 'record_id', 'version', 'workflow_state',
            'operation', 'deleted', 'replayed',
        ]);
    }

    /**
     * Describe one signed mutation plan and the safe bindings it captured.
     *
     * @return  array<string, mixed>  Closed five-minute plan response schema.
     *
     * @since   2.0.0
     */
    private function businessMutationPlanOutput(): array
    {
        return $this->closedObject([
            'plan' => $this->businessPlan(),
            'operation_id' => $this->operationId(),
            'operation' => $this->businessMutationOperation(),
            'definition_version' => ['type' => 'integer', 'minimum' => 1],
            'record_id' => $this->nullable($this->businessRecordIdentifier()),
            'record_version' => $this->nullable(['type' => 'integer', 'minimum' => 1]),
            'destructive' => ['type' => 'boolean'],
            'approval_required' => ['type' => 'boolean'],
            'expires_at' => ['type' => 'string', 'format' => 'date-time'],
        ], [
            'plan', 'operation_id', 'operation', 'definition_version', 'record_id', 'record_version',
            'destructive', 'approval_required', 'expires_at',
        ]);
    }

    /**
     * Describe a caller-bound completed business operation.
     *
     * @return  array<string, mixed>  Closed status schema from `BusinessOperationStatusService`.
     *
     * @since   2.0.0
     */
    private function businessStatusOutput(): array
    {
        return $this->closedObject([
            'operation_id' => $this->operationId(),
            'state' => ['type' => 'string', 'enum' => ['completed']],
            'operation' => ['type' => 'string', 'pattern' => '^business\\.record\\.[a-z_]+$'],
            'created_at' => ['type' => 'string', 'format' => 'date-time'],
            'completed_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'expires_at' => ['type' => 'string', 'format' => 'date-time'],
            'result' => [
                'oneOf' => [
                    $this->businessMutationOutput(),
                    $this->closedObject([
                        'approval_request_id' => $this->nullable(['type' => 'string', 'format' => 'uuid']),
                    ], ['approval_request_id']),
                ],
            ],
        ], ['operation_id', 'state', 'operation', 'created_at', 'completed_at', 'expires_at', 'result']);
    }

    /**
     * Return the schema fragment every mutating tool publishes for its `operationId`.
     *
     * Declaring the window once keeps it identical wherever it is reused, so a client is told the same
     * bounds and grammar `McpMutationGuard` enforces before it claims a lease under the identifier.
     *
     * @return  array<string, int|string>  A string schema constrained to 16 to 128 characters.
     *
     * @since   2.0.0
     */
    private function operationId(): array
    {
        return [
            'type' => 'string',
            'minLength' => 16,
            'maxLength' => 128,
            'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$',
        ];
    }

    /**
     * Return the input properties one trust-key add or rotate handler accepts.
     *
     * The two handlers name the new key differently: add accepts `keyId`, while rotate accepts
     * `newKeyId` beside `oldKeyId`. Selecting that name here keeps each closed input envelope exactly
     * aligned with its handler rather than advertising the other handler's unused identifier.
     *
     * @param   string  $identifier  `keyId` for an add or `newKeyId` for a rotation.
     *
     * @return  array<string, array<string, mixed>>  One schema fragment per shared property name.
     *
     * @since   2.0.0
     */
    private function trustKeyProperties(string $identifier): array
    {
        return [
            'operationId' => $this->operationId(),
            $identifier => ['type' => 'string'],
            'publicKeyBase64' => ['type' => 'string'],
            'vendorNamespace' => ['type' => 'string'],
            'extensionPattern' => ['type' => 'string'],
            'expiresAt' => ['type' => 'string'],
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
