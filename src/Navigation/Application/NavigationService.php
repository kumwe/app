<?php

declare(strict_types=1);

namespace Kumwe\CMS\Navigation\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Identity\Domain\Capability;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

final readonly class NavigationService
{
    public function __construct(
        private NavigationRepository $repository,
        private AuditRecorder $audit,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
        private ResourceSiteOwnershipWriter $ownership,
        private ?ContentService $content = null,
    ) {
    }

    /** @return list<MenuRecord> */
    public function menus(ExecutionContext $context): array
    {
        return array_values(array_filter(
            $this->repository->menus(),
            fn (MenuRecord $menu): bool => $this->authorization->decide(
                $context,
                Capability::fromString('navigation.manage'),
                AuthorizationResource::item('menu', $menu->id),
            )->allowed,
        ));
    }

    public function menu(ExecutionContext $context, string $id): MenuRecord
    {
        $this->authorize($context, AuthorizationResource::item('menu', $id));
        return $this->repository->menu($id) ?? throw new NavigationNotFound('The menu does not exist.');
    }

    /** @return list<MenuItemRecord> */
    public function items(ExecutionContext $context, string $menuId): array
    {
        $this->menu($context, $menuId);

        return array_values(array_filter(
            $this->repository->items($menuId),
            fn (MenuItemRecord $item): bool => $this->authorization->decide(
                $context,
                Capability::fromString('navigation.manage'),
                AuthorizationResource::item('menu_item', $item->id),
            )->allowed,
        ));
    }

    public function item(ExecutionContext $context, string $id): MenuItemRecord
    {
        $this->authorize($context, AuthorizationResource::item('menu_item', $id));
        return $this->repository->item($id) ?? throw new NavigationNotFound('The menu item does not exist.');
    }

    public function createMenu(ExecutionContext $context, string $handle, string $title): MenuRecord
    {
        $this->authorize($context, AuthorizationResource::collection('menu'));
        $handle = $this->handle($handle);
        $title = $this->title($title);
        $now = $this->clock->now();
        $menu = new MenuRecord(Uuid::uuid7()->toString(), $handle, $title, 1, $now, $now);

        return $this->transactions->transactional(function () use ($context, $menu, $now): MenuRecord {
            $this->repository->insertMenu($menu);
            $this->ownership->record(AuthorizationResource::item('menu', $menu->id), $context->site());
            $this->audit($context->actorId(), 'navigation.menu.create', 'menu', $menu->id, $now, ['version' => 1]);

            return $menu;
        });
    }

    public function updateMenu(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        string $handle,
        string $title,
    ): MenuRecord {
        $this->authorize($context, AuthorizationResource::item('menu', $id));
        $stored = $this->menu($context, $id);
        $this->assertVersion($stored->version, $expectedVersion);
        $now = $this->clock->now();
        $updated = new MenuRecord(
            $stored->id,
            $this->handle($handle),
            $this->title($title),
            $stored->version + 1,
            $stored->createdAt,
            $now,
        );

        return $this->transactions->transactional(function () use (
            $context,
            $updated,
            $expectedVersion,
            $now,
        ): MenuRecord {
            $this->repository->updateMenu($updated, $expectedVersion);
            $this->audit($context->actorId(), 'navigation.menu.update', 'menu', $updated->id, $now, [
                'version' => $updated->version,
            ]);

            return $updated;
        });
    }

    public function deleteMenu(ExecutionContext $context, string $id, int $expectedVersion): void
    {
        $this->authorize($context, AuthorizationResource::item('menu', $id));
        $stored = $this->menu($context, $id);
        $this->assertVersion($stored->version, $expectedVersion);
        $now = $this->clock->now();
        $this->transactions->transactional(function () use (
            $context,
            $id,
            $expectedVersion,
            $now,
        ): void {
            $itemIds = $this->repository->itemIdsForMenuDeletion($id, $expectedVersion);
            $this->repository->deleteMenu($id, $expectedVersion);
            foreach ($itemIds as $itemId) {
                $this->ownership->remove(
                    AuthorizationResource::item('menu_item', $itemId),
                    $context->site(),
                );
            }
            $this->ownership->remove(AuthorizationResource::item('menu', $id), $context->site());
            $this->audit($context->actorId(), 'navigation.menu.delete', 'menu', $id, $now);
        });
    }

    public function createItem(
        ExecutionContext $context,
        string $menuId,
        ?string $parentId,
        string $title,
        string $slug,
        int $position,
        ?string $targetType = null,
        ?string $contentId = null,
        ?string $targetUrl = null,
    ): MenuItemRecord {
        $this->authorize($context, AuthorizationResource::item('menu', $menuId));
        $this->menu($context, $menuId);
        $slug = $this->slug($slug);
        $position = $this->position($position);
        $legacyUntargetedContent = $targetType === null;
        [$targetType, $contentId, $targetUrl] = $this->target(
            $context,
            $targetType ?? 'content',
            $contentId,
            $targetUrl,
            $legacyUntargetedContent,
        );
        $path = $this->repository->pathForParent($menuId, $parentId, $slug);
        $this->assertPublicPath($path);
        $now = $this->clock->now();
        $item = new MenuItemRecord(
            Uuid::uuid7()->toString(),
            $menuId,
            $parentId,
            $this->title($title),
            $slug,
            $path,
            $position,
            1,
            $now,
            $now,
            $targetType,
            $contentId,
            $targetUrl,
        );

        return $this->transactions->transactional(function () use ($context, $item, $now): MenuItemRecord {
            $this->repository->insertItem($item);
            $this->ownership->record(AuthorizationResource::item('menu_item', $item->id), $context->site());
            $this->audit(
                $context->actorId(),
                'navigation.item.create',
                'menu_item',
                $item->id,
                $now,
                ['path' => $item->path],
            );

            return $item;
        });
    }

    public function updateItem(
        ExecutionContext $context,
        string $id,
        int $expectedVersion,
        ?string $parentId,
        string $title,
        string $slug,
        int $position,
        ?string $targetType = null,
        ?string $contentId = null,
        ?string $targetUrl = null,
    ): MenuItemRecord {
        $this->authorize($context, AuthorizationResource::item('menu_item', $id));
        $stored = $this->item($context, $id);
        $this->assertVersion($stored->version, $expectedVersion);
        $slug = $this->slug($slug);
        [$targetType, $contentId, $targetUrl] = $this->target(
            $context,
            $targetType ?? $stored->targetType,
            $targetType === null ? $stored->contentId : $contentId,
            $targetType === null ? $stored->targetUrl : $targetUrl,
            $targetType === null,
        );
        $this->repository->assertMoveIsAcyclic($id, $stored->menuId, $parentId);
        $path = $this->repository->pathForParent($stored->menuId, $parentId, $slug);
        $this->assertPublicPath($path);
        if ($stored->path !== $path) {
            foreach ($this->repository->items($stored->menuId) as $candidate) {
                if (str_starts_with($candidate->path, $stored->path . '/')) {
                    $this->assertPublicPath($path . substr($candidate->path, strlen($stored->path)));
                }
            }
        }
        $now = $this->clock->now();
        $updated = new MenuItemRecord(
            $stored->id,
            $stored->menuId,
            $parentId,
            $this->title($title),
            $slug,
            $path,
            $this->position($position),
            $stored->version + 1,
            $stored->createdAt,
            $now,
            $targetType,
            $contentId,
            $targetUrl,
        );

        return $this->transactions->transactional(function () use (
            $context,
            $updated,
            $expectedVersion,
            $stored,
            $now,
        ): MenuItemRecord {
            $this->repository->updateItem($updated, $expectedVersion);
            if ($stored->path !== $updated->path) {
                $this->repository->moveDescendantPaths($updated->id, $stored->path, $updated->path, $now);
            }
            $this->audit($context->actorId(), 'navigation.item.update', 'menu_item', $updated->id, $now, [
                'path' => $updated->path,
                'version' => $updated->version,
            ]);

            return $updated;
        });
    }

    public function deleteItem(ExecutionContext $context, string $id, int $expectedVersion): void
    {
        $this->authorize($context, AuthorizationResource::item('menu_item', $id));
        $stored = $this->item($context, $id);
        $this->assertVersion($stored->version, $expectedVersion);
        $now = $this->clock->now();
        $this->transactions->transactional(function () use ($context, $id, $expectedVersion, $now): void {
            $this->repository->deleteItem($id, $expectedVersion);
            $this->ownership->remove(AuthorizationResource::item('menu_item', $id), $context->site());
            $this->audit($context->actorId(), 'navigation.item.delete', 'menu_item', $id, $now);
        });
    }

    private function handle(string $handle): string
    {
        $handle = strtolower(trim($handle));
        if (preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $handle) !== 1) {
            throw new InvalidArgumentException('A menu handle must use lowercase letters, digits and underscores.');
        }

        return $handle;
    }

    private function authorize(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('navigation.manage'),
            $resource,
        );
    }

    private function slug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1 || strlen($slug) > 160) {
            throw new InvalidArgumentException('A menu item slug must be a safe lowercase URL segment.');
        }

        return $slug;
    }

    private function title(string $title): string
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 255) {
            throw new InvalidArgumentException('A navigation title must contain 1 to 255 characters.');
        }

        return $title;
    }

    private function position(int $position): int
    {
        if ($position < 0) {
            throw new InvalidArgumentException('A menu item position cannot be negative.');
        }

        return $position;
    }

    /** @return array{string, ?string, ?string} */
    private function target(
        ExecutionContext $context,
        string $targetType,
        ?string $contentId,
        ?string $targetUrl,
        bool $allowUntargetedContent = false,
    ): array {
        $targetType = strtolower(trim($targetType));
        $contentId = $this->nullable($contentId);
        $targetUrl = $this->nullable($targetUrl);

        if (!in_array($targetType, ['content', 'anchor', 'url'], true)) {
            throw new InvalidArgumentException('A navigation target type must be content, anchor or url.');
        }
        if ($targetType === 'content' && $targetUrl !== null) {
            throw new InvalidArgumentException('A content navigation target cannot contain a target URL.');
        }
        if ($targetType === 'content' && $contentId === null && !$allowUntargetedContent) {
            throw new InvalidArgumentException('A content navigation target must reference content.');
        }
        if ($targetType === 'anchor') {
            if (
                $targetUrl === null
                || preg_match('/^#[A-Za-z][A-Za-z0-9._:-]{0,190}$/D', $targetUrl) !== 1
            ) {
                throw new InvalidArgumentException('An anchor navigation target must contain a safe fragment.');
            }
        }
        if ($targetType === 'url') {
            if ($contentId !== null) {
                throw new InvalidArgumentException('A URL navigation target cannot reference content.');
            }
            if ($targetUrl === null || !$this->safeUrl($targetUrl)) {
                throw new InvalidArgumentException('A URL navigation target must contain a safe URL.');
            }
        }
        if ($contentId !== null) {
            if (
                preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
                    $contentId,
                ) !== 1
            ) {
                throw new InvalidArgumentException('A navigation content target must be a canonical UUID.');
            }
            $this->content?->get($context, $contentId);
        }

        return [$targetType, $contentId, $targetUrl];
    }

    private function nullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function safeUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1 || str_contains($url, '\\')) {
            return false;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme)) {
            return false;
        }
        $scheme = strtolower($scheme);
        if ($scheme === 'mailto') {
            return filter_var(substr($url, 7), FILTER_VALIDATE_EMAIL) !== false;
        }
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_string(parse_url($url, PHP_URL_HOST));
    }

    private function assertPublicPath(string $path): void
    {
        if (strlen($path) > 512) {
            throw new InvalidArgumentException('A navigation path cannot exceed 512 bytes.');
        }
        $firstSegment = explode('/', ltrim($path, '/'), 2)[0] ?? '';
        if (in_array($firstSegment, [
            'administrator',
            'api',
            'assets',
            'health',
            'mcp',
            'media',
            'pages',
        ], true)) {
            throw new InvalidArgumentException('A navigation path cannot use a reserved system prefix.');
        }
    }

    private function assertVersion(int $actual, int $expected): void
    {
        if ($expected < 1 || $actual !== $expected) {
            throw new NavigationVersionConflict('The navigation record changed; reload it and retry.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function audit(
        string $actorId,
        string $action,
        string $subjectType,
        string $subjectId,
        DateTimeImmutable $at,
        array $metadata = [],
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $at,
            $actorId,
            $action,
            $subjectType,
            $subjectId,
            'success',
            $metadata,
        ));
    }
}
