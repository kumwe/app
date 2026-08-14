<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

/**
 * The level an owning scope is held at: one site, a declared group of sites, or the installation.
 *
 * A resource still has exactly one owner; this names how wide that single owner is. The order the cases
 * are written in is the order of reach, and `reach()` exposes it so a scope change can be classified as
 * widening or narrowing without a table of special cases. Storage keeps the backing value verbatim in
 * `resource_site_ownership.scope_level`, so a new case would be a schema-visible change rather than a
 * private refactor.
 *
 * @since  2.0.0
 */
enum OwnershipScopeLevel: string
{
    /**
     * One named site owns the resource, which is how every resource was owned before groups existed.
     *
     * @since  2.0.0
     */
    case Site = 'site';

    /**
     * A declared, named set of sites owns the resource, and nobody outside that set may reach it.
     *
     * @since  2.0.0
     */
    case Group = 'group';

    /**
     * The installation itself owns the resource, which a human may only reach with a global grant.
     *
     * @since  2.0.0
     */
    case Installation = 'installation';

    /**
     * Report how far this level reaches, so two levels can be ordered without enumerating pairs.
     *
     * @return  int  1 for a site, 2 for a group, 3 for the installation; larger is wider.
     *
     * @since   2.0.0
     */
    public function reach(): int
    {
        return match ($this) {
            self::Site => 1,
            self::Group => 2,
            self::Installation => 3,
        };
    }

    /**
     * Whether this level reaches strictly further than another.
     *
     * @param   self  $other  Level being compared against.
     *
     * @return  bool  True only when this level is wider; equal levels are neither wider nor narrower.
     *
     * @since   2.0.0
     */
    public function widerThan(self $other): bool
    {
        return $this->reach() > $other->reach();
    }
}
