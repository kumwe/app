<?php

declare(strict_types=1);

namespace Kumwe\App\Navigation\Application;

use DateTimeImmutable;

/**
 * Stored state of a navigation menu — the named container its items hang from.
 *
 * A menu is addressed two ways: by `id` for management writes, and by `handle` for rendering, which
 * is how a site's presentation contract names the menu it draws as primary navigation without
 * embedding a generated identifier in a theme. The record itself holds no items; ask
 * `NavigationRepository::items()` for those. `version` is the optimistic-locking counter that an
 * update or delete must quote back, so a stale administrator screen cannot overwrite a newer edit.
 *
 * @since  2.0.0
 */
final readonly class MenuRecord
{
    /**
     * Capture a menu exactly as it is stored.
     *
     * @param  string             $id         UUIDv7 primary key of the menu.
     * @param  string             $handle     Stable lowercase name a theme or setting refers to the menu by.
     * @param  string             $title      Human-readable label shown to operators.
     * @param  int                $version    Optimistic-locking counter incremented by every write.
     * @param  DateTimeImmutable  $createdAt  When the menu was first stored.
     * @param  DateTimeImmutable  $updatedAt  When the menu was last written.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $id,
        public string $handle,
        public string $title,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Export the menu in the snake_case shape the HTTP API, console and MCP surfaces serialise.
     *
     * @return  array<string, mixed>  Keys named after the storage columns, timestamps as RFC 3339.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'handle' => $this->handle,
            'title' => $this->title,
            'version' => $this->version,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
