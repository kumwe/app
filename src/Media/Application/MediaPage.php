<?php

declare(strict_types=1);

namespace Kumwe\CMS\Media\Application;

final readonly class MediaPage
{
    /** @param list<MediaAsset> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
