<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use DateTimeImmutable;

/**
 * Verdict `RetryPolicy` reaches about one failed attempt: whether to run it again, and when.
 *
 * Whoever settles the job reads this instead of re-deriving anything — the classification explains the
 * verdict, and the delay and instant describe the next attempt. When `shouldRetry` is false the two
 * scheduling fields are inert, the delay zero and the instant null, so a caller burying a job can act on
 * this value without first testing which fields still mean something.
 *
 * @since  2.0.0
 */
final readonly class RetryDecision
{
    /**
     * Capture what the policy concluded about one failed attempt.
     *
     * @param  FailureClassification  $classification  Whether the fault should clear or will repeat.
     * @param  bool                   $shouldRetry     True when the fault is transient and attempts remain.
     * @param  int                    $delaySeconds    Backoff before the next attempt; zero when none follows.
     * @param  ?DateTimeImmutable     $retryAt         Instant the next attempt is due, or null when none is.
     *
     * @since  2.0.0
     */
    public function __construct(
        public FailureClassification $classification,
        public bool $shouldRetry,
        public int $delaySeconds,
        public ?DateTimeImmutable $retryAt,
    ) {
    }
}
