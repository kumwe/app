<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Trust;

use DateTimeImmutable;

/**
 * What an installation knows about its upstream revocation feed right now.
 *
 * This is the object the decision about an unreachable feed is expressed through. Kumwe applies a
 * verified list and keeps the last one in force when the origin cannot be reached — stale but valid,
 * rather than fail-closed — because a revocation feed that could take an installation offline by being
 * unreachable would hand every vendor outage, and every attacker able to drop packets, a remote kill
 * switch over sites they do not run. The local trust store is already authoritative and already fails
 * closed on what it can prove; the feed only shortens the time between an upstream compromise and an
 * operator acting on it.
 *
 * The cost of that choice is that silence has to be loud, which is what `isStale()` is for: past the
 * configured budget the Extensions screen shows the feed as stale, the synchronizer logs a warning on
 * every run, and the operator is expected to treat it as an incident rather than as background noise.
 *
 * @since  2.0.0
 */
final readonly class RevocationFeedState
{
    /**
     * Record the feed's position and its last outcome.
     *
     * @param  ?string             $origin               Configured origin, or null when no feed is set up.
     * @param  ?string             $issuer               Issuer of the last applied list, or null when none
     *         has been applied.
     * @param  int                 $appliedSequence      Highest list sequence applied; zero when none has.
     * @param  ?string             $documentSha256       Digest of the last applied envelope, or null.
     * @param  int                 $revokedKeyCount      Keys the last applied list withdrew.
     * @param  ?DateTimeImmutable  $lastSuccessAt        When a list last verified and applied.
     * @param  ?DateTimeImmutable  $lastAttemptAt        When a fetch was last attempted at all.
     * @param  ?DateTimeImmutable  $lastFailureAt        When a fetch last failed.
     * @param  ?string             $lastFailureReason    Why it failed, truncated for display.
     * @param  int                 $consecutiveFailures  Failures since the last success.
     * @param  int                 $maxStaleSeconds      Budget after which the feed reads as stale.
     *
     * @since  2.0.0
     */
    public function __construct(
        public ?string $origin,
        public ?string $issuer,
        public int $appliedSequence,
        public ?string $documentSha256,
        public int $revokedKeyCount,
        public ?DateTimeImmutable $lastSuccessAt,
        public ?DateTimeImmutable $lastAttemptAt,
        public ?DateTimeImmutable $lastFailureAt,
        public ?string $lastFailureReason,
        public int $consecutiveFailures,
        public int $maxStaleSeconds,
    ) {
    }

    /**
     * Build the state of an installation that consumes no feed at all.
     *
     * Not consuming a feed is a supported posture, not a degraded one: local revocation still works and
     * still fails closed. The screen says so plainly rather than reporting a fault.
     *
     * @return  self  A state carrying no origin and nothing applied.
     *
     * @since   2.0.0
     */
    public static function unconfigured(): self
    {
        return new self(null, null, 0, null, 0, null, null, null, null, 0, 0);
    }

    /**
     * Report whether the last successful fetch has fallen outside its freshness budget.
     *
     * A feed configured but never once fetched successfully is stale from the moment it is configured,
     * which is the honest reading: nothing has been consumed, so nothing is being enforced.
     *
     * @param   DateTimeImmutable  $at  Instant to judge freshness against, normally the clock's now.
     *
     * @return  bool  True when the feed is configured and its last success is older than the budget.
     *
     * @since   2.0.0
     */
    public function isStale(DateTimeImmutable $at): bool
    {
        if ($this->origin === null) {
            return false;
        }
        if ($this->lastSuccessAt === null) {
            return true;
        }

        return $at->getTimestamp() - $this->lastSuccessAt->getTimestamp() > $this->maxStaleSeconds;
    }

    /**
     * Export the summary the Extensions screen and the console renderers read.
     *
     * @param   DateTimeImmutable  $at  Instant staleness is judged against.
     *
     * @return  array{configured: bool, origin: ?string, issuer: ?string, sequence: int, stale: bool,
     *          last_success_at: ?string, last_failure_at: ?string, last_failure_reason: ?string,
     *          consecutive_failures: int, revoked_keys: int}  Flat, template-friendly summary.
     *
     * @since   2.0.0
     */
    public function toArray(DateTimeImmutable $at): array
    {
        return [
            'configured' => $this->origin !== null,
            'origin' => $this->origin,
            'issuer' => $this->issuer,
            'sequence' => $this->appliedSequence,
            'stale' => $this->isStale($at),
            'last_success_at' => $this->lastSuccessAt?->format(DATE_ATOM),
            'last_failure_at' => $this->lastFailureAt?->format(DATE_ATOM),
            'last_failure_reason' => $this->lastFailureReason,
            'consecutive_failures' => $this->consecutiveFailures,
            'revoked_keys' => $this->revokedKeyCount,
        ];
    }
}
