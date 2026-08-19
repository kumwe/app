<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The calendar boundary at which an allocated document number starts counting from one again.
 *
 * A gapless run is only ever gapless *within* one counter, so the reset period is what decides how many
 * counters a definition really has: `Never` keeps a single lifetime run, while `Yearly` and `Monthly`
 * split it into one run per calendar period. That choice is visible in the number itself — the period
 * segment `NumberSequenceFormat` renders is what keeps `INV-2026-000001` and `INV-2027-000001` apart —
 * so changing it on a published definition changes what the numbers mean. `FiscalPeriod` steps outside
 * the calendar entirely: its period is whatever range an extension declared over the posting timeline,
 * so its key is resolved from the record's declared posting date rather than composed here.
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
     * One counter per declared posting period containing the record's posting date.
     *
     * A fiscal period is not a calendar formula: it is a range an extension declared through the
     * posting-period surface, addressed by its stable key. `BusinessRecordService` resolves that key
     * through the `PostingPeriodCalendar` seam from the record's declared posting date — never from the
     * allocation instant — and the declared key becomes the number's period segment, so it counts toward
     * `NumberSequenceFormat::MAXIMUM_LENGTH` exactly as a calendar segment would.
     *
     * @since  2.0.0
     */
    case FiscalPeriod = 'fiscal-period';

    /**
     * Name the counter the given instant belongs to, in the sequence's own timezone.
     *
     * The key is part of the counter's identity in `business_number_sequences`, so it has to be derived
     * from the instant alone and never from the server's ambient timezone: two replicas allocating at the
     * same moment must land on the same counter row or the gapless guarantee is lost. `FiscalPeriod`
     * refuses here rather than answering: its key is a declared posting period's stable key, which no
     * instant and timezone can compute, so the caller must resolve it through `PostingPeriodCalendar` —
     * an empty or guessed answer would silently merge fiscal runs into a lifetime one.
     *
     * @param   DateTimeImmutable  $at        Instant the allocation is being made at.
     * @param   DateTimeZone       $timezone  Zone the period boundary is judged in.
     *
     * @return  string  Empty for `Never`, `YYYY` for `Yearly`, and `YYYY-MM` for `Monthly`.
     *
     * @throws  InvalidArgumentException  When asked for `FiscalPeriod`, whose key only a declared
     *          posting period can answer.
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
            self::FiscalPeriod => throw new InvalidArgumentException(
                'A fiscal-period counter key is a declared posting period\'s stable key '
                . 'and cannot be derived from an instant and timezone.',
            ),
        };
    }
}
