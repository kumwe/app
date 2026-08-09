<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

/**
 * Authoritative answer to which site owns a given resource.
 *
 * Site isolation in a multi-site installation rests on this contract: the gateway compares the site an
 * implementation names against the site the caller is executing in, and denies when the two differ. An
 * implementation must therefore be authoritative rather than best-effort — it may never fill a gap with
 * the caller's own site, because that would hand a caller any resource it happens to name. Records are
 * kept in step by `ResourceSiteOwnershipWriter`, which writes them alongside the resources themselves.
 *
 * @since  2.0.0
 */
interface ResourceSiteOwnership
{
    /**
     * Resolve the site that owns a resource.
     *
     * @param   AuthorizationResource  $resource  Target whose owning site is being established.
     *
     * @return  SiteContext  The owning site, established from durable records rather than from the caller.
     *
     * @throws  AuthorizationResourceOwnershipUnknown  When no authoritative record names an owner.
     *
     * @since   2.0.0
     */
    public function siteFor(AuthorizationResource $resource): SiteContext;
}
