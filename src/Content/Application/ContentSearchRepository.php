<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Application;

use Kumwe\CMS\Application\Authorization\SiteContext;

interface ContentSearchRepository
{
    /** @return list<ContentRecord> */
    public function searchForSite(
        SiteContext $site,
        ContentBrowseQuery $query,
        int $limit,
        int $offset,
    ): array;
}
