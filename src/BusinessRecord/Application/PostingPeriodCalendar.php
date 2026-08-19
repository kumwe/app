<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\App\BusinessRecord\Domain\PostingPeriod;

/**
 * Read seam answering which declared posting period contains an instant within one scope.
 *
 * This is the contract other core mechanisms consume when they need a period's stable key without
 * caring what a period means — the fiscal-period number-sequence reset resolves its counter period
 * through this seam rather than through any calendar of its own. A period here is only what an
 * extension declared: a named, keyed, closed-or-open half-open range. The answer is independent of
 * the period's open or closed state; refusing mutations is `PostingPeriodLock`'s separate reading of
 * the same declarations.
 *
 * @since  2.0.0
 */
interface PostingPeriodCalendar
{
    /**
     * Resolve the declared period containing an instant, preferring the narrower scope.
     *
     * An organization's own declaration beats a site-wide one covering the same instant; among
     * declarations at the same scope the one starting latest wins, and the key orders any remaining
     * tie, so overlapping declarations resolve deterministically rather than by storage order.
     *
     * @param   string             $siteIdentifier          Site whose declarations are consulted.
     * @param   ?string            $organizationIdentifier  Organization whose declarations are
     *          consulted beside the site-wide ones, or null to consult site-wide declarations only.
     * @param   DateTimeImmutable  $instant                 Moment to classify.
     *
     * @return  ?PostingPeriod  The containing declaration with its stable key, or null when no
     *          declared period covers the instant in this scope.
     *
     * @since   2.0.0
     */
    public function periodContaining(
        string $siteIdentifier,
        ?string $organizationIdentifier,
        DateTimeImmutable $instant,
    ): ?PostingPeriod;
}
