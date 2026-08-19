<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Application;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Resolved portal browser session carrying only server-owned identity and membership state.
 *
 * @since  2.0.0
 */
final readonly class PortalSession
{
    /**
     * Request attribute under which portal middleware publishes the resolved session.
     *
     * @var    string
     * @since  2.0.0
     */
    public const REQUEST_ATTRIBUTE = self::class;

    /**
     * Validate a live portal session.
     *
     * @param   string                 $id               Stored session UUID.
     * @param   PortalSessionIdentity  $identity         Live principal and membership snapshot.
     * @param   string                 $csrfToken        Independent form token.
     * @param   DateTimeImmutable      $authenticatedAt  Password authentication instant.
     * @param   ?DateTimeImmutable     $stepUpAt         Latest successful step-up instant.
     * @param   DateTimeImmutable      $expiresAt        Absolute expiry.
     *
     * @throws  InvalidArgumentException  When identity, token, or timestamps are inconsistent.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public PortalSessionIdentity $identity,
        public string $csrfToken,
        public DateTimeImmutable $authenticatedAt,
        public ?DateTimeImmutable $stepUpAt,
        public DateTimeImmutable $expiresAt,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $id) !== 1) {
            throw new InvalidArgumentException('A portal session ID must be a canonical UUID.');
        }
        if (
            strlen($csrfToken) < 32
            || strlen($csrfToken) > 512
            || preg_match('/^[A-Za-z0-9_-]+$/D', $csrfToken) !== 1
        ) {
            throw new InvalidArgumentException('A portal CSRF token is invalid.');
        }
        if (
            $expiresAt <= $authenticatedAt
            || ($stepUpAt !== null && ($stepUpAt < $authenticatedAt || $stepUpAt >= $expiresAt))
        ) {
            throw new InvalidArgumentException('Portal session timestamps are inconsistent.');
        }
    }
}
