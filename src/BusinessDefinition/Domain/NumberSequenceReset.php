<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The calendar boundary at which an allocated document number starts counting from one again.
 *
 * A gapless run is only ever gapless *within* one counter, so the reset period is what decides how many
 * counters a definition really has: `Never` keeps a single lifetime run, while `Yearly` and `Monthly`
 * split it into one run per calendar period. That choice is visible in the number itself — the period
 * segment `NumberSequenceFormat` renders is what keeps `INV-2026-000001` and `INV-2027-000001` apart —
 * so changing it on a published definition changes what the numbers mean.
 *
 * @since  2.0.0
 */
enum NumberSequenceReset: string
{
    /**
     * One lifetime counter; the number never returns to one.
     *
     * @since  2.0.0
     */
    case Never = 'never';

    /**
     * One counter per calendar year in the sequence's declared timezone.
     *
     * @since  2.0.0
     */
    case Yearly = 'yearly';

    /**
     * One counter per calendar month in the sequence's declared timezone.
     *
     * @since  2.0.0
     */
    case Monthly = 'monthly';

    /**
     * Name the counter the given instant belongs to, in the sequence's own timezone.
     *
     * The key is part of the counter's identity in `business_number_sequences`, so it has to be derived
     * from the instant alone and never from the server's ambient timezone: two replicas allocating at the
     * same moment must land on the same counter row or the gapless guarantee is lost.
     *
     * @param   DateTimeImmutable  $at        Instant the allocation is being made at.
     * @param   DateTimeZone       $timezone  Zone the period boundary is judged in.
     *
     * @return  string  Empty for `Never`, `YYYY` for `Yearly`, and `YYYY-MM` for `Monthly`.
     *
     * @since   2.0.0
     */
    public function key(DateTimeImmutable $at, DateTimeZone $timezone): string
    {
        $local = $at->setTimezone($timezone);

        return match ($this) {
            self::Never => '',
            self::Yearly => $local->format('Y'),
            self::Monthly => $local->format('Y-m'),
        };
    }
}
