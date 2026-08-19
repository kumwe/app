<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Trust;

use DateTimeImmutable;

/**
 * Port holding the durable position and outcome of one revocation feed origin.
 *
 * The stored sequence is the rollback defense, which is why it is durable rather than derived: an
 * installation must be able to say "I have already applied list 9" after a restart, so that an origin
 * serving list 7 again — through a stale cache, a downgrade attack, or a mirror that fell behind — is
 * refused instead of silently un-revoking a key. Everything else the store holds exists so an
 * unreachable feed is visible rather than silent.
 *
 * @since  2.0.0
 */
interface RevocationFeedStateStore
{
    /**
     * Read the recorded state of one origin.
     *
     * @param   string  $origin           Configured feed origin.
     * @param   int     $maxStaleSeconds  Freshness budget stamped onto the returned state.
     *
     * @return  RevocationFeedState  The recorded state, or one carrying nothing applied when the origin
     *          has never been fetched.
     *
     * @since   2.0.0
     */
    public function read(string $origin, int $maxStaleSeconds): RevocationFeedState;

    /**
     * Record that a list verified, was newer than the stored sequence, and was applied.
     *
     * @param   string             $origin           Configured feed origin.
     * @param   RevocationList     $list             The list that was applied.
     * @param   DateTimeImmutable  $at               Instant recorded as the success.
     * @param   int                $revokedKeyCount  Keys actually withdrawn from the trust store.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function recordSuccess(
        string $origin,
        RevocationList $list,
        DateTimeImmutable $at,
        int $revokedKeyCount,
    ): void;

    /**
     * Record that a fetch was attempted and did not result in an applied list.
     *
     * Both an unreachable origin and a served document that failed verification arrive here; the reason
     * text is what distinguishes them on the operator surface, because the responses differ — one is
     * tolerated as staleness, the other is an incident.
     *
     * @param   string             $origin  Configured feed origin.
     * @param   DateTimeImmutable  $at      Instant recorded as the failure.
     * @param   string             $reason  Why the attempt did not apply a list.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function recordFailure(string $origin, DateTimeImmutable $at, string $reason): void;
}
