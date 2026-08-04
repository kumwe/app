<?php

declare(strict_types=1);

namespace Kumwe\CMS\Navigation\Application;

use DateTimeImmutable;

interface NavigationRepository
{
    /** @return list<MenuRecord> */
    public function menus(): array;

    public function menu(string $id): ?MenuRecord;

    /** @return list<MenuItemRecord> */
    public function items(string $menuId): array;

    public function item(string $id): ?MenuItemRecord;

    public function insertMenu(MenuRecord $menu): void;

    public function updateMenu(MenuRecord $menu, int $expectedVersion): void;

    public function deleteMenu(string $id, int $expectedVersion): void;

    public function insertItem(MenuItemRecord $item): void;

    public function updateItem(MenuItemRecord $item, int $expectedVersion): void;

    public function deleteItem(string $id, int $expectedVersion): void;

    public function pathForParent(string $menuId, ?string $parentId, string $slug): string;

    public function assertMoveIsAcyclic(string $itemId, string $menuId, ?string $parentId): void;

    public function moveDescendantPaths(string $itemId, string $oldPath, string $newPath, DateTimeImmutable $at): void;
}
