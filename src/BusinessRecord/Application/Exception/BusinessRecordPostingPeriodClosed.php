<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Exception;

use DateTimeImmutable;

/**
 * Raised when a mutation's declared posting date falls inside a closed posting period.
 *
 * This is the temporal lock's one refusal, evaluated before the mutation fence is taken, and it is
 * deliberately its own named failure rather than a policy denial: the actor was allowed to attempt the
 * mutation — the period, not the policy, said no. Every mutation path through `BusinessRecordService`
 * reports a closed period under this code, so a delivery adapter or an extension can branch on
 * `business_record.posting_period_closed` and never on message text.
 *
 * @since  2.0.0
 */
final class BusinessRecordPostingPeriodClosed extends BusinessRecordException
{
    /**
     * Report a refused posting under the `business_record.posting_period_closed` code.
     *
     * @param  string             $periodKey    Stable key of the closed period that refused the
     *         mutation, as its declaring extension named it.
     * @param  DateTimeImmutable  $postingDate  Declared posting instant that fell inside the closed
     *         range.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $periodKey,
        public readonly DateTimeImmutable $postingDate,
    ) {
        parent::__construct(
            'business_record.posting_period_closed',
            sprintf('Posting period %s is closed for the declared posting date.', $periodKey),
        );
    }
}
