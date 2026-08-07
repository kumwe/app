<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Application;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\SiteContext;

interface SiteScopedContentRepository extends ContentRepository
{
    /** @return list<ContentRecord> */
    public function allForSite(
        SiteContext $site,
        int $limit = 100,
        bool $includeDeleted = false,
        int $offset = 0,
    ): array;

    public function findForSite(SiteContext $site, string $id, bool $includeDeleted = false): ?ContentRecord;

    public function findPublishedByIdForSite(
        SiteContext $site,
        string $id,
        DateTimeImmutable $time,
    ): ?ContentRecord;

    public function findPublishedBySlugForSite(
        SiteContext $site,
        string $slug,
        DateTimeImmutable $time,
    ): ?ContentRecord;
}
