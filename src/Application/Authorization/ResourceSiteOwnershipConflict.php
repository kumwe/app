<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

final class ResourceSiteOwnershipConflict extends \RuntimeException
{
    public function __construct(
        AuthorizationResource $resource,
        SiteContext $expectedSite,
        SiteContext $actualSite,
    ) {
        parent::__construct(sprintf(
            'Refusing to remove %s:%s ownership from site %s because it belongs to site %s.',
            $resource->type(),
            $resource->identifier(),
            $expectedSite->identifier(),
            $actualSite->identifier(),
        ));
    }
}
