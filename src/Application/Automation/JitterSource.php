<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

/**
 * Supplier of the random number a retry delay is drawn from.
 *
 * `RetryPolicy` computes how wide the backoff window is for an attempt and then asks this port for a
 * value inside it, instead of calling `random_int()` itself. That indirection is what makes the policy
 * testable: a test substitutes a source that always answers the same number and can then assert the
 * exact delay and the exact instant a retry falls due. The policy does not trust the answer — it
 * re-checks the returned value against the range it asked for and refuses one that falls outside — so
 * an implementation cannot lengthen or cancel a backoff by answering out of bounds.
 *
 * @since  2.0.0
 */
interface JitterSource
{
    /**
     * Draw one value from the inclusive range the caller offers.
     *
     * @param   int  $minimum  Lowest value the caller is prepared to accept.
     * @param   int  $maximum  Highest value the caller is prepared to accept; callers never pass a bound
     *          below the minimum.
     *
     * @return  int  A value between the two bounds, both included; the caller may re-check it.
     *
     * @since   2.0.0
     */
    public function between(int $minimum, int $maximum): int;
}
