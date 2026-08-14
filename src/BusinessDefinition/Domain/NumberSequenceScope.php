<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

use InvalidArgumentException;

/**
 * The tenancy boundary an allocated document number is unique and contiguous within.
 *
 * A number sequence is never installation-wide: it is always at least per site, because two sites sharing
 * one database are two businesses and each needs its own invoice run. `Organization` narrows it further
 * for a definition whose records are scoped to an organization branch, so each branch keeps its own
 * contiguous run instead of interleaving with its siblings. The key is resolved from the record's own
 * resolved scope rather than from caller input, which is what keeps one tenant from allocating into
 * another's counter.
 *
 * @since  2.0.0
 */
enum NumberSequenceScope: string
{
    /**
     * One counter per site; every record on the site shares the run.
     *
     * @since  2.0.0
     */
    case Site = 'site';

    /**
     * One counter per organization branch within the site.
     *
     * @since  2.0.0
     */
    case Organization = 'organization';

    /**
     * Resolve the counter key for the organization a record was scoped to.
     *
     * @param   ?string  $organizationIdentifier  Organization the record belongs to, or null when its
     *          definition's scope mode carries no organization dimension.
     *
     * @return  string  `-` for a site-wide counter, and the organization identifier for a per-branch one.
     *
     * @throws  InvalidArgumentException  When a per-organization sequence is declared on a definition whose
     *          scope mode carries no organization dimension.
     *
     * @since   2.0.0
     */
    public function key(?string $organizationIdentifier): string
    {
        if ($this === self::Site) {
            return '-';
        }
        if ($organizationIdentifier === null || $organizationIdentifier === '') {
            throw new InvalidArgumentException(
                'A per-organization number sequence requires a record scope carrying an organization.',
            );
        }

        return $organizationIdentifier;
    }
}
