<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Mcp;

use InvalidArgumentException;
use JsonException;
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
        private ?AuthenticatedPrincipal $principal = null,
    ) {
    }

    public function forPrincipal(AuthenticatedPrincipal $principal): self
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
            $principal,
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
            $this->content->list(includeDeleted: $includeDeleted),
        )];
    }

    /** @return array<string, mixed> */
    public function createContent(string $operationId, string $title, string $slug, string $body): array
    {
        $principal = $this->require('content.create');

        return $this->mutations->run($principal, 'content.create', $operationId, [
            'title' => $title, 'slug' => $slug, 'body' => $body,
        ], fn (): array => $this->content->create(
            $principal->subject(),
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
        $principal = $this->require('content.update');

        return $this->mutations->run($principal, 'content.update', $operationId, [
            'id' => $id, 'version' => $version, 'title' => $title, 'slug' => $slug, 'body' => $body,
        ], fn (): array => $this->content->update(
            $principal->subject(),
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
        $this->transitions->assertAllowed($principal, $this->content->get($id)->entry->status(), $target);

        return $this->mutations->run($principal, 'content.transition', $operationId, [
            'id' => $id, 'version' => $version, 'status' => $status,
        ], fn (): array => $this->content->transition(
            $principal->subject(),
            $id,
            $version,
            $target,
        )->toArray());
    }

    /** @return array<string, mixed> */
    public function trashContent(string $operationId, string $id, int $version): array
    {
        $principal = $this->require('content.delete');
        return $this->mutations->run(
            $principal,
            'content.trash',
            $operationId,
            compact('id', 'version'),
            fn (): array => $this->content->trash($principal->subject(), $id, $version)->toArray()
        );
    }

    /** @return array<string, mixed> */
    public function restoreContent(string $operationId, string $id, int $version): array
    {
        $principal = $this->require('content.restore');
        return $this->mutations->run(
            $principal,
            'content.restore',
            $operationId,
            compact('id', 'version'),
            fn (): array => $this->content->restore($principal->subject(), $id, $version)->toArray()
        );
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function listMenus(): array
    {
        $this->require('navigation.manage');

        return ['items' => array_map(
            static fn (MenuRecord $menu): array => $menu->toArray(),
            $this->navigation->menus(),
        )];
    }

    /** @return array<string, mixed> */
    public function createMenu(string $operationId, string $handle, string $title): array
    {
        $principal = $this->require('navigation.manage');

        return $this->mutations->run($principal, 'menu.create', $operationId, [
            'handle' => $handle, 'title' => $title,
        ], fn (): array => $this->navigation->createMenu(
            $principal->subject(),
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
            $this->navigation->items($menuId)
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
        $principal = $this->require('navigation.manage');
        $input = compact('menuId', 'title', 'slug', 'position', 'parentId');
        return $this->mutations->run(
            $principal,
            'menu-item.create',
            $operationId,
            $input,
            fn (): array => $this->navigation->createItem(
                $principal->subject(),
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

        return $this->settings->current();
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
        $principal = $this->require('settings.manage');
        $values = [
            'site_name' => $siteName,
            'homepage_slug' => $homepageSlug,
            'default_locale' => $defaultLocale,
            'timezone' => $timezone,
            'search_indexing_enabled' => $searchIndexingEnabled,
        ];

        return $this->mutations->run(
            $principal,
            'settings.update',
            $operationId,
            $values,
            function () use ($principal, $values): array {
                $this->settings->updateAll($principal->subject(), $values);

                return $this->settings->current();
            },
        );
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function listUsers(): array
    {
        $this->require('users.manage');

        return ['items' => $this->access->users()];
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
        return ['items' => $this->access->roles(), 'capabilities' => $this->access->capabilities()];
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
        $principal = $this->require('users.manage');
        return $this->mutations->run(
            $principal,
            'user.update',
            $operationId,
            compact('id', 'version', 'email', 'displayName', 'status'),
            function () use ($principal, $id, $version, $email, $displayName, $status): array {
                $this->access->updateUser(
                    $principal->subject(),
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
        $principal = $this->require('users.manage');
        return $this->mutations->run(
            $principal,
            'role.create',
            $operationId,
            compact('code', 'name'),
            fn (): array => ['id' => $this->access->createRole($principal->subject(), $code, $name)]
        );
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function listTokens(): array
    {
        $this->require('users.manage');

        return ['items' => $this->access->tokens()];
    }

    /** @return array{revoked: bool} */
    public function revokeToken(string $operationId, string $tokenId): array
    {
        $principal = $this->require('users.manage');

        return $this->mutations->run(
            $principal,
            'token.revoke',
            $operationId,
            ['token_id' => $tokenId],
            function () use ($principal, $tokenId): array {
                $this->access->revokeToken($principal->subject(), $tokenId);

                return ['revoked' => true];
            },
        );
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function listExtensions(): array
    {
        $this->require('extensions.manage');

        return ['items' => $this->extensions->installed()];
    }

    /** @return array<string, mixed> */
    public function activateExtension(string $operationId, string $identifier): array
    {
        $principal = $this->require('extensions.manage');

        return $this->mutations->run($principal, 'extension.activate', $operationId, [
            'identifier' => $identifier,
        ], fn (): array => $this->extensions->activate($identifier, $principal->subject()));
    }

    /** @return array<string, mixed> */
    public function disableExtension(string $operationId, string $identifier): array
    {
        $principal = $this->require('extensions.manage');
        return $this->mutations->run(
            $principal,
            'extension.disable',
            $operationId,
            compact('identifier'),
            fn (): array => $this->extensions->disable($identifier, $principal->subject())
        );
    }

    /** @return array{uninstalled: bool} */
    public function uninstallExtension(string $operationId, string $identifier): array
    {
        $principal = $this->require('extensions.manage');
        return $this->mutations->run(
            $principal,
            'extension.uninstall',
            $operationId,
            compact('identifier'),
            function () use ($principal, $identifier): array {
                $this->extensions->uninstall($identifier, $principal->subject());
                return ['uninstalled' => true];
            }
        );
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function listSchedules(): array
    {
        $this->require('automation.manage');

        return ['items' => $this->automation->schedules()];
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function listJobs(int $limit = 100): array
    {
        $this->require('automation.manage');
        return ['items' => $this->automation->jobs($limit)];
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

        $principal = $this->principal();
        return $this->mutations->run($principal, 'schedule.create', $operationId, [
            'name' => $name, 'cron' => $cron, 'jobType' => $jobType,
            'timezone' => $timezone, 'queue' => $queue,
        ], fn (): array => ['id' => $this->automation->createSchedule(
            $principal->subject(),
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
        $principal = $this->require('automation.manage');
        return $this->mutations->run(
            $principal,
            'schedule.update',
            $operationId,
            compact('id', 'version', 'enabled'),
            function () use ($principal, $id, $version, $enabled): array {
                $this->automation->setScheduleEnabled($principal->subject(), $id, $version, $enabled);
                return ['updated' => true];
            }
        );
    }

    /** @return array{deleted: bool} */
    public function deleteSchedule(string $operationId, string $id, int $version): array
    {
        $principal = $this->require('automation.manage');
        return $this->mutations->run(
            $principal,
            'schedule.delete',
            $operationId,
            compact('id', 'version'),
            function () use ($principal, $id, $version): array {
                $this->automation->deleteSchedule($principal->subject(), $id, $version);
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
        $principal = $this->require('automation.manage');
        return $this->mutations->run(
            $principal,
            $retry ? 'job.retry' : 'job.cancel',
            $operationId,
            compact('id'),
            function () use ($principal, $id, $retry): array {
                if ($retry) {
                    $this->automation->retryJob($principal->subject(), $id);
                } else {
                    $this->automation->cancelJob($principal->subject(), $id);
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
        return $this->principal ?? throw new InsufficientCapability('authenticated');
    }
}
