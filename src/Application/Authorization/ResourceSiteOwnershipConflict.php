<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

/**
 * Raised when ownership is withdrawn on behalf of a site that does not actually own the resource.
 *
 * `ResourceSiteOwnershipWriter::remove()` matches on the resource *and* the site the caller expects to own
 * it, so a delete that affects no row while a record still exists means the caller was wrong about the
 * owner. Failing here instead of deleting by resource alone stops one site from severing another site's
 * ownership, which would leave that resource unreachable to everyone.
 *
 * @since  2.0.0
 */
final class ResourceSiteOwnershipConflict extends \RuntimeException
{
    /**
     * Name the resource and both sites in the operator-facing message.
     *
     * @param  AuthorizationResource  $resource      Target whose ownership was being withdrawn.
     * @param  SiteContext            $expectedSite  Site the caller believed owned the resource.
     * @param  SiteContext            $actualSite    Site the surviving ownership record names.
     *
     * @since  2.0.0
     */
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
