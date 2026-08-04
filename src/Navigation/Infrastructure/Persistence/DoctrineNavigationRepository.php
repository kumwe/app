<?php

declare(strict_types=1);

namespace Kumwe\CMS\Navigation\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Navigation\Application\MenuItemRecord;
use Kumwe\CMS\Navigation\Application\MenuRecord;
use Kumwe\CMS\Navigation\Application\NavigationRepository;
use Kumwe\CMS\Navigation\Application\NavigationVersionConflict;
use RuntimeException;

final readonly class DoctrineNavigationRepository implements NavigationRepository
{
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    public function menus(): array
    {
        return array_map($this->menuFromRow(...), $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s ORDER BY title',
            $this->tables->quoted('navigation_menus'),
        )));
    }

    public function menu(string $id): ?MenuRecord
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE id = ?',
            $this->tables->quoted('navigation_menus'),
        ), [$id]);

        return $row === false ? null : $this->menuFromRow($row);
    }

    public function items(string $menuId): array
    {
        return array_map($this->itemFromRow(...), $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE menu_id = ? ORDER BY path, position, title',
            $this->tables->quoted('navigation_items'),
        ), [$menuId]));
    }

    public function item(string $id): ?MenuItemRecord
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE id = ?',
            $this->tables->quoted('navigation_items'),
        ), [$id]);

        return $row === false ? null : $this->itemFromRow($row);
    }

    public function insertMenu(MenuRecord $menu): void
    {
        $this->database->insert($this->tables->raw('navigation_menus'), [
            'id' => $menu->id,
            'handle' => $menu->handle,
            'title' => $menu->title,
            'version' => $menu->version,
            'created_at' => $menu->createdAt,
            'updated_at' => $menu->updatedAt,
        ], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
    }

    public function updateMenu(MenuRecord $menu, int $expectedVersion): void
    {
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET handle = ?, title = ?, version = ?, updated_at = ? WHERE id = ? AND version = ?',
            $this->tables->quoted('navigation_menus'),
        ), [$menu->handle, $menu->title, $menu->version, $menu->updatedAt, $menu->id, $expectedVersion], [
            Types::STRING, Types::STRING, Types::INTEGER, Types::DATETIME_IMMUTABLE, Types::GUID, Types::INTEGER,
        ]);
        $this->assertChanged($affected, 'menu');
    }

    public function deleteMenu(string $id, int $expectedVersion): void
    {
        $this->assertChanged($this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE id = ? AND version = ?',
            $this->tables->quoted('navigation_menus'),
        ), [$id, $expectedVersion]), 'menu');
    }

    public function insertItem(MenuItemRecord $item): void
    {
        $this->database->insert($this->tables->raw('navigation_items'), [
            'id' => $item->id,
            'menu_id' => $item->menuId,
            'parent_id' => $item->parentId,
            'title' => $item->title,
            'slug' => $item->slug,
            'path' => $item->path,
            'position' => $item->position,
            'version' => $item->version,
            'created_at' => $item->createdAt,
            'updated_at' => $item->updatedAt,
        ], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
    }

    public function updateItem(MenuItemRecord $item, int $expectedVersion): void
    {
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET parent_id = ?, title = ?, slug = ?, path = ?, position = ?, version = ?, '
            . 'updated_at = ? WHERE id = ? AND version = ?',
            $this->tables->quoted('navigation_items'),
        ), [
            $item->parentId, $item->title, $item->slug, $item->path, $item->position, $item->version,
            $item->updatedAt, $item->id, $expectedVersion,
        ], [
            Types::GUID, Types::STRING, Types::STRING, Types::STRING, Types::INTEGER, Types::INTEGER,
            Types::DATETIME_IMMUTABLE, Types::GUID, Types::INTEGER,
        ]);
        $this->assertChanged($affected, 'menu item');
    }

    public function deleteItem(string $id, int $expectedVersion): void
    {
        $this->assertChanged($this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE id = ? AND version = ?',
            $this->tables->quoted('navigation_items'),
        ), [$id, $expectedVersion]), 'menu item');
    }

    public function pathForParent(string $menuId, ?string $parentId, string $slug): string
    {
        if ($parentId === null) {
            return '/' . $slug;
        }

        $path = $this->database->fetchOne(sprintf(
            'SELECT path FROM %s WHERE id = ? AND menu_id = ?',
            $this->tables->quoted('navigation_items'),
        ), [$parentId, $menuId]);

        if (!is_string($path)) {
            throw new InvalidArgumentException('The selected parent is not part of this menu.');
        }

        return $path . '/' . $slug;
    }

    public function assertMoveIsAcyclic(string $itemId, string $menuId, ?string $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($itemId === $parentId) {
            throw new InvalidArgumentException('A menu item cannot be its own parent.');
        }

        $item = $this->item($itemId);
        $parent = $this->item($parentId);

        if ($item === null || $parent === null || $parent->menuId !== $menuId) {
            throw new InvalidArgumentException('The selected menu item parent is invalid.');
        }

        if (str_starts_with($parent->path . '/', $item->path . '/')) {
            throw new InvalidArgumentException('A menu item cannot move below one of its descendants.');
        }
    }

    public function moveDescendantPaths(string $itemId, string $oldPath, string $newPath, DateTimeImmutable $at): void
    {
        $root = $this->item($itemId);
        if ($root === null) {
            throw new RuntimeException('The moved menu item no longer exists.');
        }

        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, path FROM %s WHERE menu_id = ? AND path LIKE ? ORDER BY path',
            $this->tables->quoted('navigation_items'),
        ), [$root->menuId, $oldPath . '/%']);

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $path = $row['path'] ?? null;
            if (!is_string($id) || !is_string($path) || !str_starts_with($path, $oldPath . '/')) {
                throw new RuntimeException('A descendant menu path record is invalid.');
            }

            $this->assertChanged($this->database->executeStatement(sprintf(
                'UPDATE %s SET path = ?, version = version + 1, updated_at = ? WHERE id = ?',
                $this->tables->quoted('navigation_items'),
            ), [$newPath . substr($path, strlen($oldPath)), $at, $id], [
                Types::STRING, Types::DATETIME_IMMUTABLE, Types::GUID,
            ]), 'descendant menu item');
        }
    }

    /** @param array<string, mixed> $row */
    private function menuFromRow(array $row): MenuRecord
    {
        return new MenuRecord(
            $this->requiredString($row, 'id'),
            $this->requiredString($row, 'handle'),
            $this->requiredString($row, 'title'),
            $this->requiredInteger($row, 'version'),
            $this->dateTime($row['created_at'] ?? null),
            $this->dateTime($row['updated_at'] ?? null),
        );
    }

    /** @param array<string, mixed> $row */
    private function itemFromRow(array $row): MenuItemRecord
    {
        $parent = $row['parent_id'] ?? null;
        if ($parent !== null && !is_string($parent)) {
            throw new RuntimeException('A navigation parent identifier is invalid.');
        }

        return new MenuItemRecord(
            $this->requiredString($row, 'id'),
            $this->requiredString($row, 'menu_id'),
            $parent,
            $this->requiredString($row, 'title'),
            $this->requiredString($row, 'slug'),
            $this->requiredString($row, 'path'),
            $this->requiredInteger($row, 'position'),
            $this->requiredInteger($row, 'version'),
            $this->dateTime($row['created_at'] ?? null),
            $this->dateTime($row['updated_at'] ?? null),
        );
    }

    private function assertChanged(int|string $affected, string $resource): void
    {
        if ((string) $affected !== '1') {
            throw new NavigationVersionConflict(sprintf('The %s changed; reload it and retry.', $resource));
        }
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Navigation field %s is invalid.', $field));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function requiredInteger(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('Navigation field %s is invalid.', $field));
        }

        return (int) $value;
    }

    private function dateTime(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (!is_string($value)) {
            throw new RuntimeException('Navigation timestamp is invalid.');
        }

        return new DateTimeImmutable($value);
    }
}
