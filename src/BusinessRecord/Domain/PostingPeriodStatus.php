<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

/**
 * Whether a declared posting period currently admits or refuses dated business-record mutations.
 *
 * The two states are the whole vocabulary the core lock understands. What a period *means* — a fiscal
 * month, a VAT quarter, a stock-take freeze — is the declaring extension's business, expressed only
 * through the range it declares and the state it moves that range into. Values are stored verbatim in
 * `business_posting_periods.status`, so the backing strings are part of the persisted contract.
 *
 * @since  2.0.0
 */
enum PostingPeriodStatus: string
{
    /**
     * The range is declared but posting into it is currently permitted.
     *
     * A period is open when it was declared ahead of time, or when an operator re-opened it after a
     * close, which is what makes re-opening an administrative act rather than a row deletion.
     *
     * @since  2.0.0
     */
    case Open = 'open';

    /**
     * The range refuses every mutation to a record whose declared posting date falls inside it.
     *
     * @since  2.0.0
     */
    case Closed = 'closed';
}
