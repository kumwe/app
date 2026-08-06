<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Application;

final readonly class ContentPage
{
    /** @param list<ContentRecord> $items */
    public function __construct(
        public array $items,
        public ContentBrowseQuery $query,
        public bool $hasPrevious,
        public bool $hasNext,
    ) {
    }
}
