<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

interface ResourceSiteOwnershipWriter
{
    /** Record ownership in the same transaction that creates the resource. */
    public function record(AuthorizationResource $resource, SiteContext $site): void;

    /** Remove ownership in the same transaction that physically deletes the resource. */
    public function remove(AuthorizationResource $resource, SiteContext $expectedSite): void;
}
