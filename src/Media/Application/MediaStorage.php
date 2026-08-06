<?php

declare(strict_types=1);

namespace Kumwe\CMS\Media\Application;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\SiteContext;

interface MediaStorage
{
    /** @return list<MediaAsset> */
    public function all(SiteContext $site): array;

    public function find(SiteContext $site, string $id): ?MediaAsset;

    public function store(
        SiteContext $site,
        string $source,
        string $originalName,
        int $maximumBytes,
        DateTimeImmutable $createdAt,
    ): MediaAsset;

    public function delete(SiteContext $site, string $id): void;
}
