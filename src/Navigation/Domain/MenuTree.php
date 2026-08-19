<?php

declare(strict_types=1);

namespace Kumwe\App\Navigation\Domain;

use InvalidArgumentException;

/**
 * Immutable menu whose item paths are always derived from, and consistent with, the parent chain.
 *
 * This is where the structural rules of a menu live. Building or changing a tree re-derives every path
 * from scratch and re-checks the invariants — each referenced parent exists, no two siblings share a
 * slug, and the parent chain holds no cycle — so a tree that exists is a tree that is coherent. The
 * derived paths do not depend on the order items were supplied in, which makes a rebuild reproducible
 * and the rendered navigation diff-stable. Every operation returns a new tree and leaves the original
 * intact, so a rejected change cannot strand a half-applied menu.
 *
 * @since  2.0.0
 */
final readonly class MenuTree
{
    /**
     * Wraps an item map the factories have already validated and path-resolved.
     *
     * @param   string                   $id     Canonical UUID of the menu this tree represents.
     * @param   array<string, MenuItem>  $items  Items keyed by their own id, each already carrying the
     *          path its ancestry implies.
     *
     * @throws  InvalidArgumentException  When the menu id is not a canonical UUID.
     *
     * @since   2.0.0
     */
    private function __construct(private string $id, private array $items)
    {
        self::assertUuid($id);
    }

    /**
     * Builds a tree from loose items, deriving every path and enforcing every structural rule.
     *
     * Input order is irrelevant: a child may be listed before its parent and the resulting paths are the
     * same either way, which lets a caller stream rows out of storage without sorting them first.
     *
     * @param   string    $id     Canonical UUID of the menu.
     * @param   MenuItem  $items  Items to place, in any order; each may appear only once.
     *
     * @return  self  A tree in which every item carries the path its ancestry implies.
     *
     * @throws  InvalidMenuTree  When an item is supplied twice, a referenced parent is absent, two
     *          siblings share a slug, or the parent chain contains a cycle.
     * @throws  InvalidArgumentException  When the menu id is not a canonical UUID.
     *
     * @since   2.0.0
     */
    public static function create(string $id, MenuItem ...$items): self
    {
        $indexed = [];

        foreach ($items as $item) {
            if (isset($indexed[$item->id()])) {
                throw new InvalidMenuTree(sprintf('Menu item %s occurs more than once.', $item->id()));
            }

            $indexed[$item->id()] = $item;
        }

        return new self(strtolower($id), self::rebuildPaths($indexed));
    }

    /**
     * Returns the identifier of the menu this tree represents.
     *
     * @return  string  Canonical UUID, lowercased at construction.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Looks up a single item by identifier.
     *
     * @param   string  $id  Item identifier; matching ignores letter case.
     *
     * @return  MenuItem  The stored item, carrying its resolved path.
     *
     * @throws  InvalidArgumentException  When this tree holds no item with that id.
     *
     * @since   2.0.0
     */
    public function item(string $id): MenuItem
    {
        $normalizedId = strtolower($id);

        if (!isset($this->items[$normalizedId])) {
            throw new InvalidArgumentException(sprintf('Menu item %s does not exist.', $id));
        }

        return $this->items[$normalizedId];
    }

    /**
     * Returns every item in a stable, render-ready order.
     *
     * Ordering by path and then by id puts a parent ahead of its descendants and makes two trees holding
     * the same items iterate identically, whatever order they were assembled in.
     *
     * @return  list<MenuItem>  All items, ordered by path and then by id.
     *
     * @since   2.0.0
     */
    public function items(): array
    {
        $items = array_values($this->items);
        usort(
            $items,
            static fn (MenuItem $left, MenuItem $right): int => [
                $left->path(),
                $left->id(),
            ] <=> [
                $right->path(),
                $right->id(),
            ],
        );

        return $items;
    }

    /**
     * Returns a new tree with one item re-hung under a different parent.
     *
     * The whole tree is rebuilt, so the moved item's descendants travel with it and every path is
     * re-derived rather than patched. The receiver is untouched, so a rejected move costs nothing.
     *
     * @param   string   $itemId       Item to move; matching ignores letter case.
     * @param   ?string  $newParentId  Item to hang it beneath, or null to lift it to the root.
     *
     * @return  self  A new tree with the moved subtree re-pathed.
     *
     * @throws  InvalidArgumentException  When the item or the named new parent is not in this tree.
     * @throws  InvalidMenuTree  When the move would put the item below itself or one of its descendants,
     *          or the rebuilt tree would break sibling-slug uniqueness.
     *
     * @since   2.0.0
     */
    public function move(string $itemId, ?string $newParentId): self
    {
        $itemId = strtolower($itemId);
        $newParentId = $newParentId === null ? null : strtolower($newParentId);
        $item = $this->item($itemId);

        if ($newParentId !== null) {
            $this->item($newParentId);
        }

        if ($newParentId === $itemId || $this->isDescendantOf($newParentId, $itemId)) {
            throw new InvalidMenuTree('A menu item cannot be moved below itself or one of its descendants.');
        }

        $movedItems = $this->items;
        $movedItems[$itemId] = $item->placedAt($newParentId, $item->path());

        return new self($this->id, self::rebuildPaths($movedItems));
    }

    /**
     * Reports whether walking up from one item reaches another.
     *
     * @param   ?string  $candidateId  Item to walk upwards from, or null for the root, which has no
     *          ancestors and so reaches nothing.
     * @param   string   $ancestorId   Item being looked for on that chain.
     *
     * @return  bool  True when the candidate is the ancestor itself or sits somewhere beneath it.
     *
     * @since   2.0.0
     */
    private function isDescendantOf(?string $candidateId, string $ancestorId): bool
    {
        while ($candidateId !== null) {
            if ($candidateId === $ancestorId) {
                return true;
            }

            $candidateId = $this->items[$candidateId]->parentId();
        }

        return false;
    }

    /**
     * Re-derives every path from the parent chain, validating the structure on the way through.
     *
     * This is the only writer of `MenuItem::path()`. Both `create()` and `move()` route through it, so
     * there is exactly one place where a menu is declared coherent.
     *
     * @param   array<string, MenuItem>  $items  Items keyed by id, with their parent links already set.
     *
     * @return  array<string, MenuItem>  The same items under the same keys, each re-placed on its derived
     *          path.
     *
     * @throws  InvalidMenuTree  When a referenced parent is absent, two siblings share a slug, or the
     *          parent chain contains a cycle.
     *
     * @since   2.0.0
     */
    private static function rebuildPaths(array $items): array
    {
        self::assertParentsExist($items);
        self::assertSiblingSlugsAreUnique($items);

        $paths = [];
        $visiting = [];

        foreach (array_keys($items) as $id) {
            self::buildPath($id, $items, $paths, $visiting);
        }

        $rebuilt = [];

        foreach ($items as $id => $item) {
            $rebuilt[$id] = $item->placedAt($item->parentId(), $paths[$id]);
        }

        return $rebuilt;
    }

    /**
     * Rejects an item map in which some item names a parent the map does not hold.
     *
     * @param   array<string, MenuItem>  $items  Items keyed by id.
     *
     * @return  void
     *
     * @throws  InvalidMenuTree  When a parent id resolves to no item in the map.
     *
     * @since   2.0.0
     */
    private static function assertParentsExist(array $items): void
    {
        foreach ($items as $item) {
            if ($item->parentId() !== null && !isset($items[$item->parentId()])) {
                throw new InvalidMenuTree(sprintf(
                    'Parent menu item %s does not exist.',
                    $item->parentId(),
                ));
            }
        }
    }

    /**
     * Rejects an item map in which two items under the same parent claim the same slug.
     *
     * Uniqueness is scoped per parent, root items included, because that is precisely what keeps the
     * derived paths distinct.
     *
     * @param   array<string, MenuItem>  $items  Items keyed by id.
     *
     * @return  void
     *
     * @throws  InvalidMenuTree  When two items sharing a parent also share a slug.
     *
     * @since   2.0.0
     */
    private static function assertSiblingSlugsAreUnique(array $items): void
    {
        $slugs = [];

        foreach ($items as $item) {
            $key = ($item->parentId() ?? '__root__') . ':' . $item->slug();

            if (isset($slugs[$key])) {
                throw new InvalidMenuTree(sprintf(
                    'Sibling menu items cannot share the slug %s.',
                    $item->slug(),
                ));
            }

            $slugs[$key] = true;
        }
    }

    /**
     * Resolves one item's absolute path, recursing through its ancestors and memoising as it goes.
     *
     * The `$visiting` set is what turns an endless recursion into a reported cycle, so a corrupt parent
     * chain fails loudly instead of exhausting the stack.
     *
     * @param   string                   $id        Item whose path is wanted.
     * @param   array<string, MenuItem>  $items     Items keyed by id, walked to reach each parent.
     * @param   array<string, string>    $paths     Memo of paths already resolved, extended in place.
     * @param   array<string, true>      $visiting  Ids on the current branch, used to spot a cycle.
     *
     * @return  string  The absolute path, also written into `$paths` for later lookups.
     *
     * @throws  InvalidMenuTree  When the parent chain revisits an item already on the branch.
     *
     * @since   2.0.0
     */
    private static function buildPath(string $id, array $items, array &$paths, array &$visiting): string
    {
        if (isset($paths[$id])) {
            return $paths[$id];
        }

        if (isset($visiting[$id])) {
            throw new InvalidMenuTree('The menu contains a parent cycle.');
        }

        $visiting[$id] = true;
        $item = $items[$id];
        $parentPath = $item->parentId() === null
            ? ''
            : self::buildPath($item->parentId(), $items, $paths, $visiting);

        unset($visiting[$id]);

        return $paths[$id] = $parentPath . '/' . $item->slug();
    }

    /**
     * Rejects a menu identifier that is not a canonical UUID.
     *
     * @param   string  $id  Candidate menu identifier.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is not a canonical UUID of version 1 through 8.
     *
     * @since   2.0.0
     */
    private static function assertUuid(string $id): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException('A menu ID must be a canonical UUID.');
        }
    }
}
