<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Exception;

use DateTimeImmutable;

/**
 * Raised when a fiscal-period number cannot be allocated because no declared period contains the date.
 *
 * A `fiscal-period` counter is keyed by the stable key of the declared posting period containing the
 * record's posting date, so a posting date outside every declaration — or a record carrying no posting
 * date at all — names no counter. Allocating anyway, under an empty period key, would silently hand the
 * record a number from the lifetime run and lie about what the number means, so the create refuses by
 * name instead. This is not the closed-period refusal: the period is not closed here, it simply was
 * never declared, and the administrative remedy is to declare it through `PostingPeriodService`.
 *
 * @since  2.0.0
 */
final class BusinessRecordPostingPeriodUndeclared extends BusinessRecordException
{
    /**
     * Report a refused allocation under the `business_record.posting_period_undeclared` code.
     *
     * @param  ?DateTimeImmutable  $postingDate  Declared posting instant no declared period contains,
     *         or null when the record declares no posting date at all.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly ?DateTimeImmutable $postingDate)
    {
        parent::__construct(
            'business_record.posting_period_undeclared',
            'No declared posting period contains the posting date, so no fiscal-period number exists for it.',
        );
    }
}
