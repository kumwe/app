<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Mcp;

final class McpCapabilityCatalog
{
    /**
     * @return list<array{
     *   name: string, title: string, description: string, handler: string,
     *   capability: string|null, readOnly: bool, destructive: bool, idempotent: bool,
     *   inputSchema: array<string, mixed>, outputSchema: array<string, mixed>
     * }>
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
                ],
                $object,
                [
                    'operationId', 'siteName', 'homepageContentId', 'defaultLocale', 'timezone',
                    'searchIndexingEnabled',
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

    /** @return list<array<string, string>> */
    public function resources(): array
    {
        return [[
            'uri' => 'kumwe://capabilities', 'name' => 'kumwe_capabilities',
            'title' => 'Kumwe capability catalog',
            'description' => 'The tools and resources exposed by this Kumwe release.',
            'mimeType' => 'application/json', 'handler' => 'capabilityResource',
        ]];
    }

    /** @return list<array<string, string>> */
    public function prompts(): array
    {
        return [[
            'name' => 'kumwe_site_review', 'title' => 'Review a Kumwe site',
            'description' => 'Prepare a site review using authorized Kumwe tools.', 'handler' => 'siteReviewPrompt',
        ]];
    }

    /** @return array<string, string|list<string>> */
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
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $output
     * @param list<string> $required
     * @return array{
     *   name: string, title: string, description: string, handler: string,
     *   capability: string|null, readOnly: bool, destructive: bool, idempotent: bool,
     *   inputSchema: array<string, mixed>, outputSchema: array<string, mixed>
     * }
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

    /** @return array<string, int|string> */
    private function operationId(): array
    {
        return ['type' => 'string', 'minLength' => 16, 'maxLength' => 128];
    }

    /** @return array<string, array<string, mixed>> */
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

    /** @return array<string, bool|int|string|list<string>> */
    private function currentPassword(): array
    {
        return [
            'type' => ['string', 'null'],
            'minLength' => 1,
            'maxLength' => 4_096,
            'writeOnly' => true,
        ];
    }
}
