<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

/**
 * Authoritative answer to which scope owns a given resource.
 *
 * Site isolation in a multi-site installation rests on this contract: the gateway asks whether the site
 * the caller is executing in is inside the scope an implementation names, and denies when it is not. An
 * implementation must therefore be authoritative rather than best-effort — it may never fill a gap with
 * the caller's own site, because that would hand a caller any resource it happens to name. Records are
 * kept in step by `ResourceSiteOwnershipWriter`, which writes them alongside the resources themselves.
 *
 * The returned scope carries its resolved membership, so a decision never issues a second query to find
 * out which sites a group contains: group membership is administrative state an implementation resolves
 * once, not transactional state it looks up per call.
 *
 * @since  2.0.0
 */
interface ResourceSiteOwnership
{
    /**
     * Resolve the scope that owns a resource.
     *
     * @param   AuthorizationResource  $resource  Target whose owning scope is being established.
     *
     * @return  OwnershipScope  The owner, established from durable records rather than from the caller.
     *
     * @throws  AuthorizationResourceOwnershipUnknown  When no authoritative record names a reachable owner.
     *
     * @since   2.0.0
     */
    public function scopeFor(AuthorizationResource $resource): OwnershipScope;
}
