<?php

declare(strict_types=1);

namespace Kumwe\CMS\Navigation\Domain;

use InvalidArgumentException;

final readonly class MenuTree
{
    /**
     * @param array<string, MenuItem> $items
     */
    private function __construct(private string $id, private array $items)
    {
        self::assertUuid($id);
    }

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

    public function id(): string
    {
        return $this->id;
    }

    public function item(string $id): MenuItem
    {
        $normalizedId = strtolower($id);

        if (!isset($this->items[$normalizedId])) {
            throw new InvalidArgumentException(sprintf('Menu item %s does not exist.', $id));
        }

        return $this->items[$normalizedId];
    }

    /**
     * @return list<MenuItem>
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
     * @param array<string, MenuItem> $items
     *
     * @return array<string, MenuItem>
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
     * @param array<string, MenuItem> $items
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
     * @param array<string, MenuItem> $items
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
     * @param array<string, MenuItem> $items
     * @param array<string, string>   $paths
     * @param array<string, true>     $visiting
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

    private static function assertUuid(string $id): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException('A menu ID must be a canonical UUID.');
        }
    }
}
