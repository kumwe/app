<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Mcp;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Automation\AutomationManagementService;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Domain\ContentStatus;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Identity\Application\Administration\AccessControlService;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Navigation\Application\MenuRecord;
use Kumwe\CMS\Navigation\Application\MenuItemRecord;
use Kumwe\CMS\Navigation\Application\NavigationService;
use Kumwe\CMS\Site\Application\SiteSettings;
use Kumwe\CMS\Workflow\Application\ContentTransitionAuthorizer;
use Kumwe\CMS\Identity\Domain\UserStatus;
use Psr\Clock\ClockInterface;

final readonly class KumweMcpHandlers
{
    public function __construct(
        private McpCapabilityCatalog $catalog,
        private ContentService $content,
        private NavigationService $navigation,
        private AccessControlService $access,
        private SiteSettings $settings,
        private ExtensionManager $extensions,
        private AutomationManagementService $automation,
        private ContentTransitionAuthorizer $transitions,
        private McpMutationGuard $mutations,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
        private ?ExecutionContext $executionContext = null,
    ) {
    }

    public function forContext(ExecutionContext $context): self
    {
        return new self(
            $this->catalog,
            $this->content,
            $this->navigation,
            $this->access,
            $this->settings,
            $this->extensions,
            $this->automation,
            $this->transitions,
            $this->mutations,
            $this->clock,
            $this->authorization,
            $context,
        );
    }

    /** @return array<string, string|list<string>> */
    public function discover(): array
    {
        return $this->catalog->publicSummary();
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function listContent(bool $includeDeleted = false): array
    {
        $this->require('content.read');

        return ['items' => array_map(
            static fn (ContentRecord $record): array => $record->toArray(),
            $this->content->list($this->context(), includeDeleted: $includeDeleted),
        )];
    }

    /** @return array<string, mixed> */
    public function createContent(string $operationId, string $title, string $slug, string $body): array
    {
        $this->require('content.create');
        $this->preauthorize($operationId, 'content.create', AuthorizationResource::collection('content'));

        return $this->mutations->run($this->context($operationId), 'content.create', $operationId, [
            'title' => $title, 'slug' => $slug, 'body' => $body,
        ], fn (): array => $this->content->create(
            $this->context($operationId),
            $title,
            $slug,
            ['body' => $body],
        )->toArray());
    }

    /** @return array<string, mixed> */
    public function updateContent(
        string $operationId,
        string $id,
        int $version,
        string $title,
        string $slug,
        string $body,
    ): array {
        $this->require('content.update');
        $this->preauthorize($operationId, 'content.update', AuthorizationResource::item('content', $id));

        return $this->mutations->run($this->context($operationId), 'content.update', $operationId, [
            'id' => $id, 'version' => $version, 'title' => $title, 'slug' => $slug, 'body' => $body,
        ], fn (): array => $this->content->update(
            $this->context($operationId),
            $id,
            $version,
            $title,
            $slug,
            ['body' => $body],
        )->toArray());
    }

    /** @return array<string, mixed> */
    public function transitionContent(string $operationId, string $id, int $version, string $status): array
    {
        $principal = $this->principal();
        $target = ContentStatus::from($status);
        $stored = $this->content->get($this->context($operationId), $id);
        $this->transitions->assertAllowed(
            $principal,
            $stored->entry->status(),
            $target,
        );
        $this->preauthorize(
            $operationId,
            $this->transitionAction($stored->entry->status(), $target),
            AuthorizationResource::item('content', $id),
        );

        return $this->mutations->run($this->context($operationId), 'content.transition', $operationId, [
            'id' => $id, 'version' => $version, 'status' => $status,
        ], fn (): array => $this->content->transition(
            $this->context($operationId),
            $id,
            $version,
            $target,
        )->toArray());
    }

    /** @return array<string, mixed> */
    public function trashContent(string $operationId, string $id, int $version): array
    {
        $this->require('content.delete');
        $this->preauthorize($operationId, 'content.delete', AuthorizationResource::item('content', $id));
        return $this->mutations->run(
            $this->context($operationId),
            'content.trash',
            $operationId,
            compact('id', 'version'),
            fn (): array => $this->content->trash($this->context($operationId), $id, $version)->toArray()
        );
    }

    /** @return array<string, mixed> */
    public function restoreContent(string $operationId, string $id, int $version): array
    {
        $this->require('content.restore');
        $this->preauthorize($operationId, 'content.restore', AuthorizationResource::item('content', $id));
        return $this->mutations->run(
            $this->context($operationId),
            'content.restore',
            $operationId,
            compact('id', 'version'),
            fn (): array => $this->content->restore($this->context($operationId), $id, $version)->toArray()
        );
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function listMenus(): array
    {
        $this->require('navigation.manage');

        return ['items' => array_map(
            static fn (MenuRecord $menu): array => $menu->toArray(),
            $this->navigation->menus($this->context()),
        )];
    }

    /** @return array<string, mixed> */
    public function createMenu(string $operationId, string $handle, string $title): array
    {
        $this->require('navigation.manage');
        $this->preauthorize($operationId, 'navigation.manage', AuthorizationResource::collection('menu'));

        return $this->mutations->run($this->context($operationId), 'menu.create', $operationId, [
            'handle' => $handle, 'title' => $title,
        ], fn (): array => $this->navigation->createMenu(
            $this->context($operationId),
            $handle,
            $title,
        )->toArray());
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function listMenuItems(string $menuId): array
    {
        $this->require('navigation.manage');
        return ['items' => array_map(
            static fn (MenuItemRecord $item): array => $item->toArray(),
            $this->navigation->items($this->context(), $menuId)
        )];
    }

    /** @return array<string, mixed> */
    public function createMenuItem(
        string $operationId,
        string $menuId,
        string $title,
        string $slug,
        int $position = 0,
        string $parentId = '',
    ): array {
        $this->require('navigation.manage');
        $this->preauthorize($operationId, 'navigation.manage', AuthorizationResource::item('menu', $menuId));
        $input = compact('menuId', 'title', 'slug', 'position', 'parentId');
        return $this->mutations->run(
            $this->context($operationId),
            'menu-item.create',
            $operationId,
            $input,
            fn (): array => $this->navigation->createItem(
                $this->context($operationId),
                $menuId,
                $parentId === '' ? null : $parentId,
                $title,
                $slug,
                $position,
            )->toArray()
        );
    }

    /** @return array<string, mixed> */
    public function getSettings(): array
    {
        $this->require('settings.manage');

        return $this->settings->managed($this->context());
    }

    /** @return array<string, mixed> */
    public function updateSettings(
        string $operationId,
        string $siteName,
        string $homepageSlug,
        string $defaultLocale,
        string $timezone,
        bool $searchIndexingEnabled,
    ): array {
        $this->require('settings.manage');
        $this->preauthorize(
            $operationId,
            'settings.manage',
            AuthorizationResource::item('site', $this->context()->site()->identifier()),
        );
        $values = [
            'site_name' => $siteName,
            'homepage_slug' => $homepageSlug,
            'default_locale' => $defaultLocale,
            'timezone' => $timezone,
            'search_indexing_enabled' => $searchIndexingEnabled,
        ];

        return $this->mutations->run(
            $this->context($operationId),
            'settings.update',
            $operationId,
            $values,
            function () use ($operationId, $values): array {
                $this->settings->updateAll($this->context($operationId), $values);

                return $this->settings->managed($this->context($operationId));
            },
        );
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function listUsers(): array
    {
        $this->require('users.manage');

        return ['items' => $this->access->users($this->context())];
    }

    /**
     * @return array{
     *   items: list<array<string, mixed>>,
     *   capabilities: list<array{code: string, description: string}>
     * }
     */
    public function listRoles(): array
    {
        $this->require('users.manage');
        return [
            'items' => $this->access->roles($this->context()),
            'capabilities' => $this->access->capabilities($this->context()),
        ];
    }

    /** @return array{updated: bool} */
    public function updateUser(
        string $operationId,
        string $id,
        int $version,
        string $email,
        string $displayName,
        string $status,
    ): array {
        $this->require('users.manage');
        $this->preauthorize($operationId, 'users.manage', AuthorizationResource::item('user', $id));
        return $this->mutations->run(
            $this->context($operationId),
            'user.update',
            $operationId,
            compact('id', 'version', 'email', 'displayName', 'status'),
            function () use ($operationId, $id, $version, $email, $displayName, $status): array {
                $this->access->updateUser(
                    $this->context($operationId),
                    $id,
                    $email,
                    $displayName,
                    UserStatus::from($status),
                    $version,
                );
                return ['updated' => true];
            }
        );
    }

    /** @return array{id: string} */
    public function createRole(string $operationId, string $code, string $name): array
    {
        $this->require('users.manage');
        $this->preauthorize($operationId, 'users.manage', AuthorizationResource::collection('role'));
        return $this->mutations->run(
            $this->context($operationId),
            'role.create',
            $operationId,
            compact('code', 'name'),
            fn (): array => ['id' => $this->access->createRole($this->context($operationId), $code, $name)]
        );
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function listTokens(): array
    {
        $this->require('users.manage');

        return ['items' => $this->access->tokens($this->context())];
    }

    /** @return array{revoked: bool} */
    public function revokeToken(string $operationId, string $tokenId): array
    {
        $this->require('users.manage');
        $this->preauthorize($operationId, 'users.manage', AuthorizationResource::item('api_token', $tokenId));

        return $this->mutations->run(
            $this->context($operationId),
            'token.revoke',
            $operationId,
            ['token_id' => $tokenId],
            function () use ($operationId, $tokenId): array {
                $this->access->revokeToken($this->context($operationId), $tokenId);

                return ['revoked' => true];
            },
        );
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function listExtensions(): array
    {
        $this->require('extensions.manage');

        return ['items' => $this->extensions->installed($this->context())];
    }

    /** @return array<string, mixed> */
    public function activateExtension(string $operationId, string $identifier): array
    {
        $this->require('extensions.manage');
        $this->preauthorize(
            $operationId,
            'extensions.manage',
            AuthorizationResource::item('extension', $identifier),
        );

        return $this->mutations->run($this->context($operationId), 'extension.activate', $operationId, [
            'identifier' => $identifier,
        ], fn (): array => $this->extensions->activate($identifier, $this->context($operationId)));
    }

    /** @return array<string, mixed> */
    public function disableExtension(string $operationId, string $identifier): array
    {
        $this->require('extensions.manage');
        $this->preauthorize(
            $operationId,
            'extensions.manage',
            AuthorizationResource::item('extension', $identifier),
        );
        return $this->mutations->run(
            $this->context($operationId),
            'extension.disable',
            $operationId,
            compact('identifier'),
            fn (): array => $this->extensions->disable($identifier, $this->context($operationId))
        );
    }

    /** @return array{uninstalled: bool} */
    public function uninstallExtension(string $operationId, string $identifier): array
    {
        $this->require('extensions.manage');
        $this->preauthorize(
            $operationId,
            'extensions.manage',
            AuthorizationResource::item('extension', $identifier),
        );
        return $this->mutations->run(
            $this->context($operationId),
            'extension.uninstall',
            $operationId,
            compact('identifier'),
            function () use ($operationId, $identifier): array {
                $this->extensions->uninstall($identifier, $this->context($operationId));
                return ['uninstalled' => true];
            }
        );
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function listSchedules(): array
    {
        $this->require('automation.manage');

        return ['items' => $this->automation->schedules($this->context())];
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function listJobs(int $limit = 100): array
    {
        $this->require('automation.manage');
        return ['items' => $this->automation->jobs($this->context(), $limit)];
    }

    /** @return array{id: string} */
    public function createSchedule(
        string $operationId,
        string $name,
        string $cron,
        string $jobType,
        string $timezone = 'UTC',
        string $queue = 'default',
    ): array {
        $this->require('automation.manage');

        $this->preauthorize($operationId, 'automation.manage', AuthorizationResource::collection('schedule'));
        return $this->mutations->run($this->context($operationId), 'schedule.create', $operationId, [
            'name' => $name, 'cron' => $cron, 'jobType' => $jobType,
            'timezone' => $timezone, 'queue' => $queue,
        ], fn (): array => ['id' => $this->automation->createSchedule(
            $this->context($operationId),
            $name,
            $cron,
            $timezone,
            $jobType,
            [],
            $queue,
            $this->clock->now(),
        )]);
    }

    /** @return array{updated: bool} */
    public function setScheduleEnabled(
        string $operationId,
        string $id,
        int $version,
        bool $enabled,
    ): array {
        $this->require('automation.manage');
        $this->preauthorize($operationId, 'automation.manage', AuthorizationResource::item('schedule', $id));
        return $this->mutations->run(
            $this->context($operationId),
            'schedule.update',
            $operationId,
            compact('id', 'version', 'enabled'),
            function () use ($operationId, $id, $version, $enabled): array {
                $this->automation->setScheduleEnabled($this->context($operationId), $id, $version, $enabled);
                return ['updated' => true];
            }
        );
    }

    /** @return array{deleted: bool} */
    public function deleteSchedule(string $operationId, string $id, int $version): array
    {
        $this->require('automation.manage');
        $this->preauthorize($operationId, 'automation.manage', AuthorizationResource::item('schedule', $id));
        return $this->mutations->run(
            $this->context($operationId),
            'schedule.delete',
            $operationId,
            compact('id', 'version'),
            function () use ($operationId, $id, $version): array {
                $this->automation->deleteSchedule($this->context($operationId), $id, $version);
                return ['deleted' => true];
            }
        );
    }

    /** @return array{updated: bool} */
    public function retryJob(string $operationId, string $id): array
    {
        return $this->jobAction($operationId, $id, true);
    }

    /** @return array{updated: bool} */
    public function cancelJob(string $operationId, string $id): array
    {
        return $this->jobAction($operationId, $id, false);
    }

    /** @return array{updated: bool} */
    private function jobAction(string $operationId, string $id, bool $retry): array
    {
        $this->require('automation.manage');
        $this->preauthorize($operationId, 'automation.manage', AuthorizationResource::item('job', $id));
        return $this->mutations->run(
            $this->context($operationId),
            $retry ? 'job.retry' : 'job.cancel',
            $operationId,
            compact('id'),
            function () use ($operationId, $id, $retry): array {
                if ($retry) {
                    $this->automation->retryJob($this->context($operationId), $id);
                } else {
                    $this->automation->cancelJob($this->context($operationId), $id);
                }
                return ['updated' => true];
            }
        );
    }

    /** @throws JsonException */
    public function capabilityResource(): string
    {
        return json_encode(
            $this->catalog->publicSummary(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /** @return list<array{role: string, content: string}> */
    public function siteReviewPrompt(string $focus = 'content'): array
    {
        if (!in_array($focus, ['content', 'seo', 'structure', 'extensions'], true)) {
            throw new InvalidArgumentException('The site review focus is not supported.');
        }

        return [[
            'role' => 'user',
            'content' => sprintf('Review the Kumwe site with a %s focus and propose explicit changes.', $focus),
        ]];
    }

    private function require(string $capability): AuthenticatedPrincipal
    {
        $principal = $this->principal();
        $value = Capability::fromString($capability);
        if (!$principal->hasCapability($value)) {
            throw new InsufficientCapability($capability);
        }

        return $principal;
    }

    private function principal(): AuthenticatedPrincipal
    {
        return $this->executionContext?->principal()
            ?? throw new InsufficientCapability('authenticated');
    }

    private function context(?string $operationId = null): ExecutionContext
    {
        $context = $this->executionContext
            ?? throw new InsufficientCapability('authenticated');

        return $operationId === null
            ? $context
            : $context->child('mcp-' . $operationId, $operationId);
    }

    private function preauthorize(string $operationId, string $action, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $this->context($operationId),
            Capability::fromString($action),
            $resource,
        );
    }

    private function transitionAction(ContentStatus $from, ContentStatus $target): string
    {
        return match (true) {
            $from === ContentStatus::Draft && $target === ContentStatus::Review => 'content.submit',
            $from === ContentStatus::Review && $target === ContentStatus::Draft => 'content.review',
            $target === ContentStatus::Published => 'content.publish',
            $from === ContentStatus::Published && $target === ContentStatus::Draft => 'content.unpublish',
            $target === ContentStatus::Archived => 'content.archive',
            $from === ContentStatus::Archived && $target === ContentStatus::Draft => 'content.restore',
            default => 'content.update',
        };
    }
}
