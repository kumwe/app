<?php

declare(strict_types=1);

namespace Kumwe\CMS\Navigation\Domain;

use InvalidArgumentException;

final readonly class MenuItem
{
    private function __construct(
        private string $id,
        private string $title,
        private string $slug,
        private ?string $parentId,
        private string $path,
    ) {
        self::assertUuid($id, 'menu item');

        if ($parentId !== null) {
            self::assertUuid($parentId, 'parent menu item');
        }

        $titleLength = mb_strlen(trim($title));

        if ($titleLength < 1 || $titleLength > 255) {
            throw new InvalidArgumentException('A menu item title must contain between 1 and 255 characters.');
        }

        if (
            mb_strlen($slug) > 160
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1
        ) {
            throw new InvalidArgumentException(
                'A menu item slug must contain lowercase ASCII letters, digits, and single hyphens.',
            );
        }

        if ($path !== '' && preg_match('#^/[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$#D', $path) !== 1) {
            throw new InvalidArgumentException('A menu item path must be an absolute path composed of valid slugs.');
        }
    }

    public static function create(string $id, string $title, string $slug, ?string $parentId = null): self
    {
        return new self(strtolower($id), trim($title), $slug, $parentId === null ? null : strtolower($parentId), '');
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function parentId(): ?string
    {
        return $this->parentId;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function placedAt(?string $parentId, string $path): self
    {
        return new self($this->id, $this->title, $this->slug, $parentId, $path);
    }

    private static function assertUuid(string $id, string $subject): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('A %s ID must be a canonical UUID.', $subject));
        }
    }
}
