<?php

declare(strict_types=1);

namespace Kumwe\CMS\Navigation\Application;

use DateTimeImmutable;

final readonly class MenuItemRecord
{
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
    ) {
    }

    /** @return array<string, mixed> */
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
            'version' => $this->version,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
