<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

/**
 * Answers which sites still point at a resource that is about to move out of their reach.
 *
 * Narrowing an owning scope is not the inverse of widening it. Widening only adds sites; narrowing takes
 * them away, and a site that has already built records around a shared resource would be left pointing
 * at something it can no longer see. This port is how the scope-change service finds that out before it
 * happens, so narrowing can be refused with the affected sites named instead of silently orphaning them.
 *
 * An implementation reports only sites drawn from the list it is given, and reports nothing when it has
 * no opinion. Several implementations are composed, because the references worth protecting come from
 * different bounded contexts: core knows about scoped grants, and an extension that stores its own
 * cross-references contributes an implementation that knows about those.
 *
 * @since  2.0.0
 */
interface ResourceOwnershipReferences
{
    /**
     * Name the sites, among those about to lose reach, whose records still refer to the resource.
     *
     * @param   AuthorizationResource  $resource  Resource whose owning scope is about to narrow.
     * @param   list<string>           $sites     Site identifiers that would lose reach; never empty.
     *
     * @return  list<string>  The subset that still refers to the resource, in site-identifier order;
     *          empty when narrowing would strand nothing this implementation knows about.
     *
     * @since   2.0.0
     */
    public function sitesReferencing(AuthorizationResource $resource, array $sites): array;
}
