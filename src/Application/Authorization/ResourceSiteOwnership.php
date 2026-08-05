<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

interface ResourceSiteOwnership
{
    public function siteFor(AuthorizationResource $resource): SiteContext;
}
