<?php

declare(strict_types=1);

namespace Kumwe\CMS\Media\Application;

use DateTimeImmutable;

final readonly class MediaAsset
{
    public function __construct(
        public string $id,
        public string $name,
        public string $mimeType,
        public int $size,
        public DateTimeImmutable $createdAt,
        public string $path,
        public bool $deletable = true,
    ) {
    }

    /** @return array<string, bool|int|string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'size_label' => self::sizeLabel($this->size),
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'url' => '/media/' . rawurlencode($this->id) . '/' . rawurlencode($this->name),
            'is_image' => str_starts_with($this->mimeType, 'image/'),
            'deletable' => $this->deletable,
        ];
    }

    private static function sizeLabel(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1_048_576) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes / 1_048_576, 1) . ' MB';
    }
}
