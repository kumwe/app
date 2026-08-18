<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\CMS\BusinessRecord\Domain\PostingPeriod;

/**
 * Store the posting-period declarations live in, serving both their administration and the lock.
 *
 * `PostingPeriodService` writes declarations through this port and lists them back for operators;
 * `PostingPeriodLock` asks it the one question every mutation pays for — is there a closed range over
 * this posting date. The pure containment read that other mechanisms consume is the separate, smaller
 * `PostingPeriodCalendar` seam; one adapter typically implements both over the same table.
 *
 * @since  2.0.0
 */
interface PostingPeriodRepository
{
    /**
     * Read one declaration by its scope and stable key.
     *
     * @param   string   $siteIdentifier          Site the declaration belongs to.
     * @param   ?string  $organizationIdentifier  Organization scope of the declaration, or null for a
     *          site-wide one.
     * @param   string   $key                     Stable key the declaration was made under.
     *
     * @return  ?PostingPeriod  The declaration, or null when the scope holds none under that key.
     *
     * @since   2.0.0
     */
    public function find(string $siteIdentifier, ?string $organizationIdentifier, string $key): ?PostingPeriod;

    /**
     * Store one declaration, replacing the state a previous declaration under the same key holds.
     *
     * @param   PostingPeriod  $period  Declaration to persist; its site, organization and key are the
     *          stored identity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function save(PostingPeriod $period): void;

    /**
     * List the declarations that govern one scope, site-wide ones included.
     *
     * @param   string   $siteIdentifier          Site whose declarations are listed.
     * @param   ?string  $organizationIdentifier  Organization whose declarations are listed beside the
     *          site-wide ones, or null to list every declaration on the site.
     *
     * @return  list<PostingPeriod>  Declarations ordered by their range start and then by key.
     *
     * @since   2.0.0
     */
    public function listFor(string $siteIdentifier, ?string $organizationIdentifier): array;

    /**
     * Resolve the closed declaration containing an instant, preferring the narrower scope.
     *
     * This is the lock's read: only declarations whose status is closed answer, an organization's own
     * declaration beats a site-wide one, and remaining ties resolve by latest range start and then by
     * key, so the refusal names one deterministic period.
     *
     * @param   string             $siteIdentifier          Site whose declarations are consulted.
     * @param   ?string            $organizationIdentifier  Organization consulted beside the site-wide
     *          declarations, or null for site-wide declarations only.
     * @param   DateTimeImmutable  $instant                 Posting instant to classify.
     *
     * @return  ?PostingPeriod  The closed declaration refusing this instant, or null when every
     *          covering declaration is open or none exists.
     *
     * @since   2.0.0
     */
    public function closedPeriodContaining(
        string $siteIdentifier,
        ?string $organizationIdentifier,
        DateTimeImmutable $instant,
    ): ?PostingPeriod;
}
