<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

/**
 * Raised when a posting-period administrative command contradicts the declaration it addresses.
 *
 * `PostingPeriodService` refuses to close a period that is already closed, to re-open one that is
 * absent or already open, and to re-declare an existing key over a different range — a period's range
 * is part of its identity, so moving it silently would re-scope every refusal already made under that
 * key. Each refusal names its own reason in the message; the code stays one value so callers branch on
 * the family.
 *
 * @since  2.0.0
 */
final class BusinessRecordPostingPeriodConflict extends BusinessRecordException
{
    /**
     * Report a contradicted period declaration under the `business_record.posting_period_conflict` code.
     *
     * @param  string  $reason  Operator-facing sentence naming which rule the command contradicted.
     *
     * @since  2.0.0
     */
    public function __construct(string $reason)
    {
        parent::__construct('business_record.posting_period_conflict', $reason);
    }
}
