<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

use Kumwe\App\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;

/**
 * Counts failed sign-ins and throttled attempts as security events while the wrapped budget decides.
 *
 * Every password surface — administrator, portal and the high-impact credential guard — spends the same
 * attempt budget, so this is the one seam that sees all of them. The decorator adds nothing to the
 * decision: a throttle is rethrown unchanged and a failure is recorded exactly as before. The digests it
 * is handed never reach the counter; the only label is the event kind.
 *
 * @since  2.0.0
 */
final readonly class MeteredAuthenticationRateLimiter implements AuthenticationRateLimiter
{
    /**
     * Bind the decorator to the budget it wraps and the counter it increments.
     *
     * @param  AuthenticationRateLimiter  $inner    Budget that decides and records attempts.
     * @param  MetricRecorder             $metrics  Recorder of `kumwe_security_events_total`.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AuthenticationRateLimiter $inner,
        private MetricRecorder $metrics,
    ) {
    }

    /**
     * Refuse a spent budget through the wrapped limiter, counting the refusal.
     *
     * @param   string  $subjectDigest  Keyed digest of the normalised email being authenticated.
     * @param   string  $sourceDigest   Keyed digest of the origin the attempt arrives from.
     *
     * @return  void
     *
     * @throws  AuthenticationThrottled  When the pair has exhausted its attempts for the current window.
     *
     * @since   2.0.0
     */
    public function assertAllowed(string $subjectDigest, string $sourceDigest): void
    {
        try {
            $this->inner->assertAllowed($subjectDigest, $sourceDigest);
        } catch (AuthenticationThrottled $throttled) {
            $this->metrics->increment(MetricCatalog::SECURITY_EVENTS, ['event' => 'authentication_throttled']);

            throw $throttled;
        }
    }

    /**
     * Record the attempt through the wrapped limiter, counting a failed one.
     *
     * @param   string  $subjectDigest  Keyed digest of the normalised email that was authenticated.
     * @param   string  $sourceDigest   Keyed digest of the origin the attempt arrived from.
     * @param   bool    $succeeded      Whether the presented credential verified.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(string $subjectDigest, string $sourceDigest, bool $succeeded): void
    {
        if (!$succeeded) {
            $this->metrics->increment(MetricCatalog::SECURITY_EVENTS, ['event' => 'authentication_failed']);
        }
        $this->inner->record($subjectDigest, $sourceDigest, $succeeded);
    }
}
