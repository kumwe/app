<?php

declare(strict_types=1);

namespace Kumwe\CMS\Navigation\Application;

use DateTimeImmutable;

final readonly class MenuRecord
{
    public function __construct(
        public string $id,
        public string $handle,
        public string $title,
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
            'handle' => $this->handle,
            'title' => $this->title,
            'version' => $this->version,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
