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

/**
 * Doctrine DBAL implementation of the navigation repository, backed by two relational tables.
 *
 * Menus live in `navigation_menus` and their entries in `navigation_items`, both addressed through
 * `TableNames` so the configured prefix is applied and quoted in one place. Two concerns are this
 * adapter's own. Optimistic concurrency: every update and delete carries the version the caller read,
 * and a statement that matches no row is reported as `NavigationVersionConflict` rather than passing
 * silently. Row hygiene: a driver row is untyped, so each column is checked as it is mapped and a
 * missing or wrongly typed value is refused, keeping malformed data out of the application layer.
 * Entry paths are stored, not recomputed on read, which is why the move operations here rewrite them
 * explicitly.
 *
 * @since  2.0.0
 */
final readonly class DoctrineNavigationRepository implements NavigationRepository
{
    /**
     * Binds the repository to the connection and table-name resolver it works through.
     *
     * @param  Connection  $database  DBAL connection every navigation statement runs on.
     * @param  TableNames  $tables    Resolver applying the configured prefix to the navigation tables.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Reads every menu in the installation.
     *
     * @return  list<MenuRecord>  All menus, ordered by title; empty when none exist.
     *
     * @throws  RuntimeException  When a stored menu row lacks a column or holds the wrong type.
     *
     * @since   2.0.0
     */
    public function menus(): array
    {
        return array_map($this->menuFromRow(...), $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s ORDER BY title',
            $this->tables->quoted('navigation_menus'),
        )));
    }

    /**
     * Reads one menu by identifier.
     *
     * @param   string  $id  UUID of the menu, as stored in the `id` column.
     *
     * @return  ?MenuRecord  The menu, or null when no row carries that id.
     *
     * @throws  RuntimeException  When the stored row lacks a column or holds the wrong type.
     *
     * @since   2.0.0
     */
    public function menu(string $id): ?MenuRecord
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE id = ?',
            $this->tables->quoted('navigation_menus'),
        ), [$id]);

        return $row === false ? null : $this->menuFromRow($row);
    }

    /**
     * Reads every entry of one menu, in the order a renderer wants to walk them.
     *
     * @param   string  $menuId  UUID of the menu whose entries are wanted.
     *
     * @return  list<MenuItemRecord>  Entries ordered by path, then position, then title; empty when the
     *          menu holds none or does not exist.
     *
     * @throws  RuntimeException  When a stored entry row lacks a column or holds the wrong type.
     *
     * @since   2.0.0
     */
    public function items(string $menuId): array
    {
        return array_map($this->itemFromRow(...), $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE menu_id = ? ORDER BY path, position, title',
            $this->tables->quoted('navigation_items'),
        ), [$menuId]));
    }

    /**
     * Reads one menu entry by identifier, without restricting it to a menu.
     *
     * @param   string  $id  UUID of the entry, as stored in the `id` column.
     *
     * @return  ?MenuItemRecord  The entry, or null when no row carries that id.
     *
     * @throws  RuntimeException  When the stored row lacks a column or holds the wrong type.
     *
     * @since   2.0.0
     */
    public function item(string $id): ?MenuItemRecord
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE id = ?',
            $this->tables->quoted('navigation_items'),
        ), [$id]);

        return $row === false ? null : $this->itemFromRow($row);
    }

    /**
     * Writes a new menu row.
     *
     * @param   MenuRecord  $menu  Menu to store; its version is written as supplied, so the caller owns
     *          where the version sequence starts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function insertMenu(MenuRecord $menu): void
    {
        $this->database->insert($this->tables->raw('navigation_menus'), [
            'id' => $menu->id,
            'handle' => $menu->handle,
            'title' => $menu->title,
            'version' => $menu->version,
            'created_at' => $menu->createdAt,
            'updated_at' => $menu->updatedAt,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Overwrites a menu row, but only while it still carries the version the caller read.
     *
     * @param   MenuRecord  $menu             Menu in its new state, already carrying the raised version.
     * @param   int         $expectedVersion  Version the caller read, matched in the `WHERE` clause.
     *
     * @return  void
     *
     * @throws  NavigationVersionConflict  When no row matched the id and expected version, meaning
     *          another writer got there first.
     *
     * @since   2.0.0
     */
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

    /**
     * Locks a menu at the expected version and lists the entry ids its deletion will take with it.
     *
     * Deleting the menu row cascades to its entries in the database, which leaves a caller no way to
     * learn afterwards which entry ids to clean up authorization ownership rows for. This method
     * collects them first and takes `FOR UPDATE` on the menu row, so it belongs in the same transaction
     * as the delete; run outside one, the list can go stale before it is used.
     *
     * @param   string  $id               UUID of the menu about to be deleted.
     * @param   int     $expectedVersion  Version the caller read, matched against the locked row.
     *
     * @return  list<non-empty-string>  Ids of the entries belonging to the menu, ordered by id; empty
     *          when the menu holds none.
     *
     * @throws  NavigationVersionConflict  When the menu is gone or no longer at the expected version.
     * @throws  RuntimeException  When a stored entry identifier is not a non-empty string.
     *
     * @since   2.0.0
     */
    public function itemIdsForMenuDeletion(string $id, int $expectedVersion): array
    {
        $version = $this->database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE id = ? FOR UPDATE',
            $this->tables->quoted('navigation_menus'),
        ), [$id]);
        if (
            (!is_int($version) && (!is_string($version) || preg_match('/^[0-9]+$/D', $version) !== 1))
            || (int) $version !== $expectedVersion
        ) {
            throw new NavigationVersionConflict('The menu changed; reload it and retry.');
        }

        $ids = $this->database->fetchFirstColumn(sprintf(
            'SELECT id FROM %s WHERE menu_id = ? ORDER BY id',
            $this->tables->quoted('navigation_items'),
        ), [$id]);
        foreach ($ids as $itemId) {
            if (!is_string($itemId) || $itemId === '') {
                throw new RuntimeException('A navigation item identifier is invalid.');
            }
        }

        /** @var array<non-empty-string> $ids */
        return array_values($ids);
    }

    /**
     * Deletes a menu row, but only while it still carries the version the caller read.
     *
     * The statement removes the menu row alone; the database cascades the delete to its entries.
     *
     * @param   string  $id               UUID of the menu to delete.
     * @param   int     $expectedVersion  Version the caller read, matched in the `WHERE` clause.
     *
     * @return  void
     *
     * @throws  NavigationVersionConflict  When no row matched the id and expected version.
     *
     * @since   2.0.0
     */
    public function deleteMenu(string $id, int $expectedVersion): void
    {
        $this->assertChanged($this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE id = ? AND version = ?',
            $this->tables->quoted('navigation_menus'),
        ), [$id, $expectedVersion]), 'menu');
    }

    /**
     * Writes a new menu entry row.
     *
     * @param   MenuItemRecord  $item  Entry to store, carrying the path the application already derived
     *          from its parent.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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
            'target_type' => $item->targetType,
            'content_id' => $item->contentId,
            'target_url' => $item->targetUrl,
            'template' => $item->template,
            'color_scheme' => $item->colorScheme,
            'version' => $item->version,
            'created_at' => $item->createdAt,
            'updated_at' => $item->updatedAt,
        ], [
            'id' => Types::GUID,
            'menu_id' => Types::GUID,
            'parent_id' => Types::GUID,
            'target_type' => Types::STRING,
            'content_id' => Types::GUID,
            'target_url' => Types::STRING,
            'template' => Types::STRING,
            'color_scheme' => Types::STRING,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Overwrites a menu entry, but only while it still carries the version the caller read.
     *
     * Only this entry's own path is written. Paths of the entries below it are left alone, which is what
     * `moveDescendantPaths()` exists to finish.
     *
     * @param   MenuItemRecord  $item             Entry in its new state, already carrying the raised
     *          version.
     * @param   int             $expectedVersion  Version the caller read, matched in the `WHERE` clause.
     *
     * @return  void
     *
     * @throws  NavigationVersionConflict  When no row matched the id and expected version.
     *
     * @since   2.0.0
     */
    public function updateItem(MenuItemRecord $item, int $expectedVersion): void
    {
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET parent_id = ?, title = ?, slug = ?, path = ?, position = ?, target_type = ?, '
            . 'content_id = ?, target_url = ?, template = ?, color_scheme = ?, version = ?, updated_at = ? '
            . 'WHERE id = ? AND version = ?',
            $this->tables->quoted('navigation_items'),
        ), [
            $item->parentId, $item->title, $item->slug, $item->path, $item->position, $item->targetType,
            $item->contentId, $item->targetUrl, $item->template, $item->colorScheme, $item->version,
            $item->updatedAt, $item->id, $expectedVersion,
        ], [
            Types::GUID, Types::STRING, Types::STRING, Types::STRING, Types::INTEGER, Types::STRING,
            Types::GUID, Types::STRING, Types::STRING, Types::STRING, Types::INTEGER,
            Types::DATETIME_IMMUTABLE, Types::GUID, Types::INTEGER,
        ]);
        $this->assertChanged($affected, 'menu item');
    }

    /**
     * Deletes one menu entry, but only while it still carries the version the caller read.
     *
     * @param   string  $id               UUID of the entry to delete.
     * @param   int     $expectedVersion  Version the caller read, matched in the `WHERE` clause.
     *
     * @return  void
     *
     * @throws  NavigationVersionConflict  When no row matched the id and expected version.
     *
     * @since   2.0.0
     */
    public function deleteItem(string $id, int $expectedVersion): void
    {
        $this->assertChanged($this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE id = ? AND version = ?',
            $this->tables->quoted('navigation_items'),
        ), [$id, $expectedVersion]), 'menu item');
    }

    /**
     * Works out the absolute path an entry would occupy under a given parent.
     *
     * The parent's stored path is the source of truth, so a child's path is that path with one segment
     * appended rather than a fresh walk up the parent chain.
     *
     * @param   string   $menuId    UUID of the menu the entry belongs to; the parent must be in it too.
     * @param   ?string  $parentId  UUID of the intended parent, or null to place the entry at the root.
     * @param   string   $slug      URL segment the entry contributes.
     *
     * @return  string  Leading-slash path the entry would resolve to.
     *
     * @throws  InvalidArgumentException  When the named parent is not an entry of that menu.
     *
     * @since   2.0.0
     */
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

    /**
     * Refuses a re-parenting that would fold a subtree into itself or move it out of its menu.
     *
     * Ancestry is tested with the stored paths rather than by walking parent links, so the check costs
     * two reads whatever the nesting depth. Moving an entry to the root can never create a cycle and
     * returns immediately.
     *
     * @param   string   $itemId    UUID of the entry being moved.
     * @param   string   $menuId    UUID of the menu the entry must stay inside.
     * @param   ?string  $parentId  UUID of the intended new parent, or null to move it to the root.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the entry would become its own parent, either entry is
     *          missing, the parent belongs to another menu, or the parent already sits below the entry.
     *
     * @since   2.0.0
     */
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

    /**
     * Rewrites the stored paths beneath a moved entry so they sit under its new path.
     *
     * Each descendant is updated on its own so that its version advances and its `updated_at` matches
     * the move, which keeps optimistic concurrency honest for callers still holding stale copies. It
     * runs after the moved entry itself has been updated and belongs in that same transaction.
     *
     * @param   string             $itemId   UUID of the entry that moved.
     * @param   string             $oldPath  Path the entry held before the move, stripped as a prefix.
     * @param   string             $newPath  Path the entry holds now, written in the prefix's place.
     * @param   DateTimeImmutable  $at       Timestamp recorded as `updated_at` on every rewritten row.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the moved entry no longer exists, or a descendant row does not
     *          carry a usable id and path.
     * @throws  NavigationVersionConflict  When rewriting a descendant matches no row.
     *
     * @since   2.0.0
     */
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

    /**
     * Maps one driver row onto a menu record, refusing anything malformed.
     *
     * @param   array<string, mixed>  $row  Associative row selected from `navigation_menus`.
     *
     * @return  MenuRecord  The typed menu record.
     *
     * @throws  RuntimeException  When a required column is absent or holds the wrong type.
     *
     * @since   2.0.0
     */
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

    /**
     * Maps one driver row onto a menu entry record, refusing anything malformed.
     *
     * A row written before navigation targets existed carries no usable `target_type`; such a row reads
     * back as a content target, which is what those entries have always meant.
     *
     * @param   array<string, mixed>  $row  Associative row selected from `navigation_items`.
     *
     * @return  MenuItemRecord  The typed entry record.
     *
     * @throws  RuntimeException  When a required column is absent or holds the wrong type.
     *
     * @since   2.0.0
     */
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
            is_string($row['target_type'] ?? null) ? $row['target_type'] : 'content',
            $this->nullableString($row, 'content_id'),
            $this->nullableString($row, 'target_url'),
            $this->nullableString($row, 'template'),
            $this->nullableString($row, 'color_scheme'),
        );
    }

    /**
     * Turns a versioned statement that changed no row into a conflict the caller can act on.
     *
     * A versioned update or delete matches exactly one row when the caller's expectation held, so any
     * other count means the record moved on beneath them.
     *
     * @param   int|string  $affected  Row count the driver reported; some drivers return it as a string.
     * @param   string      $resource  Noun naming what was being written, quoted into the operator-facing
     *          message.
     *
     * @return  void
     *
     * @throws  NavigationVersionConflict  When the affected-row count is anything other than one.
     *
     * @since   2.0.0
     */
    private function assertChanged(int|string $affected, string $resource): void
    {
        if ((string) $affected !== '1') {
            throw new NavigationVersionConflict(sprintf('The %s changed; reload it and retry.', $resource));
        }
    }

    /**
     * Reads a column that has to hold a non-empty string.
     *
     * @param   array<string, mixed>  $row    Associative row being mapped.
     * @param   string                $field  Column name to read.
     *
     * @return  string  The column value, guaranteed non-empty.
     *
     * @throws  RuntimeException  When the column is absent, empty, or not a string.
     *
     * @since   2.0.0
     */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Navigation field %s is invalid.', $field));
        }

        return $value;
    }

    /**
     * Reads a column that has to hold an integer, accepting the digit strings some drivers hand back.
     *
     * @param   array<string, mixed>  $row    Associative row being mapped.
     * @param   string                $field  Column name to read.
     *
     * @return  int  The column value as an integer.
     *
     * @throws  RuntimeException  When the column is absent, or holds neither an integer nor a run of
     *          digits.
     *
     * @since   2.0.0
     */
    private function requiredInteger(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('Navigation field %s is invalid.', $field));
        }

        return (int) $value;
    }

    /**
     * Reads an optional column, treating an empty string as absent.
     *
     * @param   array<string, mixed>  $row    Associative row being mapped.
     * @param   string                $field  Column name to read.
     *
     * @return  ?string  The column value, or null when it is absent or empty.
     *
     * @throws  RuntimeException  When the column holds something that is neither null nor a string.
     *
     * @since   2.0.0
     */
    private function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Navigation field %s is invalid.', $field));
        }

        return $value;
    }

    /**
     * Normalises whatever the driver returned for a timestamp column into an immutable date.
     *
     * Drivers differ: some hydrate a date object, others hand back the raw string, so both are accepted
     * rather than pinning the mapper to one platform.
     *
     * @param   mixed  $value  Raw timestamp column value from a navigation row.
     *
     * @return  DateTimeImmutable  The timestamp, converted when the driver returned another date type.
     *
     * @throws  RuntimeException  When the value is neither a date object nor a string.
     * @throws  \DateMalformedStringException  When the string cannot be read as a date.
     *
     * @since   2.0.0
     */
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
