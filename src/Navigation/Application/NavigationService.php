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
    ): MenuItemRecord {
        $this->authorize($context, AuthorizationResource::item('menu', $menuId));
        $this->menu($context, $menuId);
        $slug = $this->slug($slug);
        $position = $this->position($position);
        $path = $this->repository->pathForParent($menuId, $parentId, $slug);
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
    ): MenuItemRecord {
        $this->authorize($context, AuthorizationResource::item('menu_item', $id));
        $stored = $this->item($context, $id);
        $this->assertVersion($stored->version, $expectedVersion);
        $slug = $this->slug($slug);
        $this->repository->assertMoveIsAcyclic($id, $stored->menuId, $parentId);
        $path = $this->repository->pathForParent($stored->menuId, $parentId, $slug);
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
