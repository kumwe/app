<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

/**
 * Tenancy dimensions the records of one business entity are partitioned by.
 *
 * A definition declares the mode once and two collaborators then have to agree on it: the physical
 * schema compiler emits a `site_identifier` or `organization_identifier` control column only for the
 * dimensions the mode names, and `RecordScope` refuses any identifier combination the mode does not
 * describe, so the identifiers the query compiler binds into a record statement are always the ones the
 * mode admits. On the request path the site half is read off the execution context rather than taken
 * from caller input, leaving the organization as the only dimension a caller names. Because the stored
 * columns follow from the mode, the compatibility analyzer classifies changing it on a published
 * definition as destructive.
 *
 * @since  2.0.0
 */
enum ScopeMode: string
{
    /**
     * Records belong to the installation as a whole and carry neither scope identifier.
     *
     * @since  2.0.0
     */
    case Installation = 'installation';

    /**
     * Records belong to one site, which is read off the execution context rather than requested.
     *
     * @since  2.0.0
     */
    case Site = 'site';

    /**
     * Records belong to one organization branch and are not partitioned by site at all.
     *
     * The organization is the one scope dimension a caller supplies, and it is mandatory under this mode.
     *
     * @since  2.0.0
     */
    case Organization = 'organization';

    /**
     * Records belong to one organization within one site and carry both identifiers.
     *
     * @since  2.0.0
     */
    case SiteOrganization = 'site_organization';
}
