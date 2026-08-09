<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

/**
 * Write side of the resource-to-site registry that `ResourceSiteOwnership` reads.
 *
 * Ownership is only trustworthy when it appears and disappears together with the resource it describes,
 * so implementations are called from inside the caller's transaction rather than opening one of their
 * own. Both failure directions matter: a resource left without a row becomes unreachable, since the
 * gateway denies it with `resource_site_unknown`, while a row outliving its resource keeps asserting an
 * owner that no longer exists. Removal is therefore matched against the owner the caller expects, and
 * says so when the record disagrees rather than deleting whatever it finds.
 *
 * @since  2.0.0
 */
interface ResourceSiteOwnershipWriter
{
    /**
     * Record ownership in the same transaction that creates the resource.
     *
     * @param   AuthorizationResource  $resource  Resource being created; a collection has no owner to record.
     * @param   SiteContext            $site      Site that will own it for the rest of its life.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(AuthorizationResource $resource, SiteContext $site): void;

    /**
     * Remove ownership in the same transaction that physically deletes the resource.
     *
     * The expected site is part of the match, so a caller acting for the wrong site withdraws nothing and
     * is told so, rather than orphaning a resource that belongs to another site.
     *
     * @param   AuthorizationResource  $resource      Resource being deleted; a collection has no row to remove.
     * @param   SiteContext            $expectedSite  Site the caller believes owns the resource.
     *
     * @return  void
     *
     * @throws  ResourceSiteOwnershipConflict  When a record exists but names a different owning site.
     * @throws  AuthorizationResourceOwnershipUnknown  When no ownership record exists for the resource.
     *
     * @since   2.0.0
     */
    public function remove(AuthorizationResource $resource, SiteContext $expectedSite): void;
}
