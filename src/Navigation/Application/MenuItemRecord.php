<?php

declare(strict_types=1);

namespace Kumwe\CMS\Navigation\Application;

use DateTimeImmutable;

/**
 * Stored state of a single navigation menu item as the application layer passes it around.
 *
 * The item carries its materialised `path` instead of deriving one on read, so the public site can
 * match an incoming request against one column and the administrator UI can render a tree without
 * walking parents; `NavigationService` recomputes that path, and every descendant path, whenever a
 * move changes it. `version` is the optimistic-locking counter a writer quotes back, and the
 * `targetType`/`contentId`/`targetUrl` triple is a discriminated target rather than three
 * independent columns: which of the two nullable values carries meaning depends on the type.
 *
 * @since  2.0.0
 */
final readonly class MenuItemRecord
{
    /**
     * Capture a menu item exactly as it is stored.
     *
     * @param  string             $id          UUIDv7 primary key of the item.
     * @param  string             $menuId      Menu the item belongs to; an item never moves between menus.
     * @param  ?string            $parentId    Parent item, or null when the item sits at the menu root.
     * @param  string             $title       Label the navigation renders for this item.
     * @param  string             $slug        Lowercase URL segment this item contributes to the path.
     * @param  string             $path        Absolute site path: the parent's path joined with this slug.
     * @param  int                $position    Sort order among siblings; lower values render first.
     * @param  int                $version     Optimistic-locking counter incremented by every write.
     * @param  DateTimeImmutable  $createdAt   When the item was first stored.
     * @param  DateTimeImmutable  $updatedAt   When the item was last written.
     * @param  string             $targetType  What the item points at: `content`, `anchor` or `url`.
     * @param  ?string            $contentId   Content the item resolves to, for `content` and `anchor` targets.
     * @param  ?string            $targetUrl   Fragment for an `anchor` target, or the link for a `url` target.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $id,
        public string $menuId,
        public ?string $parentId,
        public string $title,
        public string $slug,
        public string $path,
        public int $position,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public string $targetType = 'content',
        public ?string $contentId = null,
        public ?string $targetUrl = null,
    ) {
    }

    /**
     * Export the item in the snake_case shape the HTTP API, console and MCP surfaces serialise.
     *
     * @return  array<string, mixed>  Keys named after the storage columns, timestamps as RFC 3339.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'menu_id' => $this->menuId,
            'parent_id' => $this->parentId,
            'title' => $this->title,
            'slug' => $this->slug,
            'path' => $this->path,
            'position' => $this->position,
            'target_type' => $this->targetType,
            'content_id' => $this->contentId,
            'target_url' => $this->targetUrl,
            'version' => $this->version,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
