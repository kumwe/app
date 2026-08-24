<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

/**
 * Scoped durable recovery envelope and fixed-window rate-limit persistence port.
 *
 * @since  2.0.0
 */
interface StudioRecoveryRepository
{
    /**
     * Load a recovery envelope within its actor, session and resource scope.
     *
     * @param   string  $actorId             Trusted actor identifier.
     * @param   string  $sessionBinding      Trusted browser-session binding.
     * @param   string  $resourceContextKey  Opaque resource context key.
     *
     * @return  string|null  Canonical envelope bytes or null.
     *
     * @since   2.0.0
     */
    public function loadEnvelope(string $actorId, string $sessionBinding, string $resourceContextKey): ?string;

    /**
     * Save canonical recovery bytes within their complete trusted scope.
     *
     * @param   string  $actorId                Trusted actor identifier.
     * @param   string  $sessionBinding         Trusted browser-session binding.
     * @param   string  $resourceContextKey     Opaque resource context key.
     * @param   string  $canonicalEnvelope      Exact canonical envelope bytes.
     * @param   int     $updatedAtMilliseconds  Server update instant in epoch milliseconds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function saveEnvelope(
        string $actorId,
        string $sessionBinding,
        string $resourceContextKey,
        string $canonicalEnvelope,
        int $updatedAtMilliseconds,
    ): void;

    /**
     * Discard a recovery envelope only within its complete trusted scope.
     *
     * @param   string  $actorId             Trusted actor identifier.
     * @param   string  $sessionBinding      Trusted browser-session binding.
     * @param   string  $resourceContextKey  Opaque resource context key.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function discardEnvelope(string $actorId, string $sessionBinding, string $resourceContextKey): void;

    /**
     * Atomically consume one fixed-window unit or return its remaining delay.
     *
     * @param   string  $scopeDigest         Complete recovery-write scope digest.
     * @param   int     $nowMilliseconds     Server instant in epoch milliseconds.
     * @param   int     $windowMilliseconds  Fixed window duration.
     * @param   int     $maximumRequests     Maximum accepted writes per window.
     *
     * @return  int|null  Remaining milliseconds when refused, otherwise null.
     *
     * @since   2.0.0
     */
    public function consumeRateLimit(
        string $scopeDigest,
        int $nowMilliseconds,
        int $windowMilliseconds,
        int $maximumRequests,
    ): ?int;
}
