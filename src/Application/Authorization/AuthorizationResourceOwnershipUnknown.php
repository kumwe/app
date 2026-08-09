<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

/**
 * Raised when nothing authoritative records which site owns a resource.
 *
 * Site isolation cannot be decided without an owner, and guessing one would let a caller reach across
 * sites, so the resolver refuses instead. `DenyByDefaultAuthorizationGateway` catches this and fails
 * closed with the `core.site-ownership.v1` policy and a `resource_site_unknown` reason rather than falling
 * back to the calling site; `ResourceSiteOwnershipWriter::remove()` raises it when the row it was asked to
 * delete is absent. Reaching an operator, it means a resource exists without its ownership row — created
 * outside the transaction that should have recorded it, or already deleted.
 *
 * @since  2.0.0
 */
final class AuthorizationResourceOwnershipUnknown extends \RuntimeException
{
    /**
     * Name the unowned resource in the operator-facing message.
     *
     * @param  AuthorizationResource  $resource  Target whose owning site could not be established.
     *
     * @since  2.0.0
     */
    public function __construct(AuthorizationResource $resource)
    {
        parent::__construct(sprintf(
            'No authoritative site ownership exists for %s:%s.',
            $resource->type(),
            $resource->identifier(),
        ));
    }
}
