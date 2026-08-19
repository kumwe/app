<?php

declare(strict_types=1);

namespace Kumwe\App\Navigation\Application;

use DateTimeImmutable;

/**
 * Port through which navigation menus and their items are read and written.
 *
 * The port is deliberately wider than plain CRUD: path materialisation, cycle rejection and the
 * cascading path rewrite live here because they need set-based access to the stored tree, and doing
 * them in the store keeps `NavigationService` free of a full in-memory tree load on every edit. Every
 * mutation takes the `version` the caller read and refuses when the stored row has moved on, so an
 * implementation must make that check part of the same statement rather than a read followed by a
 * write. Reads are unfiltered: site scoping and capability checks belong to the callers above.
 *
 * @since  2.0.0
 */
interface NavigationRepository
{
    /**
     * List every stored menu.
     *
     * @return  list<MenuRecord>  All menus in a stable order, empty when none exist.
     *
     * @since   2.0.0
     */
    public function menus(): array;

    /**
     * Look up one menu by its identifier.
     *
     * @param   string  $id  UUID of the menu to load.
     *
     * @return  ?MenuRecord  The stored menu, or null when no menu carries that identifier.
     *
     * @since   2.0.0
     */
    public function menu(string $id): ?MenuRecord;

    /**
     * List every item belonging to one menu.
     *
     * Items come back ordered by path first, so a caller walking the result meets a parent before its
     * children and can build a tree in a single pass.
     *
     * @param   string  $menuId  UUID of the menu whose items are wanted.
     *
     * @return  list<MenuItemRecord>  The menu's items, empty when the menu is empty or unknown.
     *
     * @since   2.0.0
     */
    public function items(string $menuId): array;

    /**
     * Look up one menu item by its identifier.
     *
     * @param   string  $id  UUID of the item to load.
     *
     * @return  ?MenuItemRecord  The stored item, or null when no item carries that identifier.
     *
     * @since   2.0.0
     */
    public function item(string $id): ?MenuItemRecord;

    /**
     * Store a new menu.
     *
     * @param   MenuRecord  $menu  Fully formed menu to write, already carrying version 1.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertMenu(MenuRecord $menu): void;

    /**
     * Overwrite a stored menu, provided nobody else has written it since it was read.
     *
     * @param   MenuRecord  $menu             New state, already carrying the incremented version.
     * @param   int         $expectedVersion  Version the caller read, which the stored row must still hold.
     *
     * @return  void
     *
     * @throws  NavigationVersionConflict  When the stored menu is gone or no longer at that version.
     *
     * @since   2.0.0
     */
    public function updateMenu(MenuRecord $menu, int $expectedVersion): void;

    /**
     * Claim a menu for deletion and report the items that will go with it.
     *
     * Called inside the deleting transaction and before `deleteMenu()`, so the implementation is
     * expected to lock the menu row while it checks the version. The returned identifiers are what the
     * caller uses to retract the per-item site-ownership records that the cascade would otherwise leave
     * behind, since the rows themselves disappear with the menu.
     *
     * @param   string  $id               UUID of the menu about to be deleted.
     * @param   int     $expectedVersion  Version the caller read, which the stored menu must still hold.
     *
     * @return  list<string>  Identifiers of every item under the menu, empty when it has none.
     *
     * @throws  NavigationVersionConflict  When the stored menu is gone or no longer at that version.
     *
     * @since   2.0.0
     */
    public function itemIdsForMenuDeletion(string $id, int $expectedVersion): array;

    /**
     * Delete a menu and, through the stored cascade, every item beneath it.
     *
     * @param   string  $id               UUID of the menu to delete.
     * @param   int     $expectedVersion  Version the caller read, which the stored menu must still hold.
     *
     * @return  void
     *
     * @throws  NavigationVersionConflict  When the stored menu is gone or no longer at that version.
     *
     * @since   2.0.0
     */
    public function deleteMenu(string $id, int $expectedVersion): void;

    /**
     * Store a new menu item.
     *
     * @param   MenuItemRecord  $item  Fully formed item to write, with its path already resolved.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertItem(MenuItemRecord $item): void;

    /**
     * Overwrite a stored menu item, provided nobody else has written it since it was read.
     *
     * Only the item itself is touched. When the write changes its path, the caller follows up with
     * `moveDescendantPaths()` to bring the subtree along.
     *
     * @param   MenuItemRecord  $item             New state, already carrying the incremented version.
     * @param   int             $expectedVersion  Version the caller read, which the row must still hold.
     *
     * @return  void
     *
     * @throws  NavigationVersionConflict  When the stored item is gone or no longer at that version.
     *
     * @since   2.0.0
     */
    public function updateItem(MenuItemRecord $item, int $expectedVersion): void;

    /**
     * Delete a menu item and, through the stored cascade, its descendants.
     *
     * @param   string  $id               UUID of the item to delete.
     * @param   int     $expectedVersion  Version the caller read, which the stored item must still hold.
     *
     * @return  void
     *
     * @throws  NavigationVersionConflict  When the stored item is gone or no longer at that version.
     *
     * @since   2.0.0
     */
    public function deleteItem(string $id, int $expectedVersion): void;

    /**
     * Work out the absolute path an item would occupy under a given parent.
     *
     * The parent is resolved against the named menu, which is what stops an edit from grafting an item
     * onto a branch of some other menu.
     *
     * @param   string   $menuId    UUID of the menu the item belongs to.
     * @param   ?string  $parentId  UUID of the intended parent, or null to place the item at the root.
     * @param   string   $slug      Already-validated URL segment the item contributes.
     *
     * @return  string  Absolute path beginning with a slash, ending in the supplied slug.
     *
     * @throws  \InvalidArgumentException  When the named parent is not an item of that menu.
     *
     * @since   2.0.0
     */
    public function pathForParent(string $menuId, ?string $parentId, string $slug): string;

    /**
     * Refuse a reparent that would close a loop in the tree.
     *
     * Callers invoke this before computing the new path, so a move that would make an item its own
     * ancestor is rejected while the stored tree is still intact. Moving an item to the root is always
     * acyclic and returns without a store read.
     *
     * @param   string   $itemId    UUID of the item being moved.
     * @param   string   $menuId    UUID of the menu both the item and the intended parent must belong to.
     * @param   ?string  $parentId  UUID of the intended parent, or null when moving to the root.
     *
     * @return  void
     *
     * @throws  \InvalidArgumentException  When the parent is the item itself, one of its descendants,
     *          unknown, or part of another menu.
     *
     * @since   2.0.0
     */
    public function assertMoveIsAcyclic(string $itemId, string $menuId, ?string $parentId): void;

    /**
     * Rewrite the stored paths of everything beneath an item that has just moved.
     *
     * Run inside the same transaction as the `updateItem()` that moved the root, since between the two
     * statements the subtree still records the old prefix. Each rewritten descendant has its own
     * version bumped, so an editor holding a stale copy of a moved child is caught on their next write.
     *
     * @param   string             $itemId   UUID of the item that moved; its descendants are rewritten.
     * @param   string             $oldPath  Path prefix the subtree currently carries.
     * @param   string             $newPath  Path prefix replacing it.
     * @param   DateTimeImmutable  $at       Timestamp recorded as the update time on every rewritten row.
     *
     * @return  void
     *
     * @throws  NavigationVersionConflict  When a descendant row cannot be rewritten as written.
     *
     * @since   2.0.0
     */
    public function moveDescendantPaths(string $itemId, string $oldPath, string $newPath, DateTimeImmutable $at): void;
}
