<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain\StepUp;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Replacement browser session minted as part of a successful step-up transaction.
 *
 * @since  2.0.0
 */
final readonly class RotatedStepUpSession
{
    /**
     * Validate the fresh session and its one-time browser secrets.
     *
     * @param   string             $sessionId    Canonical UUID of the replacement session row.
     * @param   string             $cookieToken  Opaque high-entropy token disclosed only to the browser.
     * @param   string             $csrfToken    Independent high-entropy CSRF secret.
     * @param   DateTimeImmutable  $expiresAt    Absolute session expiry.
     *
     * @throws  InvalidArgumentException  When an identifier or secret is malformed.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $sessionId,
        public string $cookieToken,
        public string $csrfToken,
        public DateTimeImmutable $expiresAt,
    ) {
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}'
            . '-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di';
        if (preg_match($uuid, $sessionId) !== 1) {
            throw new InvalidArgumentException('A rotated step-up session ID must be a canonical UUID.');
        }
        if (
            strlen($cookieToken) < 43
            || strlen($cookieToken) > 512
            || strlen($csrfToken) < 32
            || strlen($csrfToken) > 512
            || preg_match('/^[A-Za-z0-9_-]+$/D', $cookieToken) !== 1
            || preg_match('/^[A-Za-z0-9_-]+$/D', $csrfToken) !== 1
        ) {
            throw new InvalidArgumentException('Rotated step-up session secrets are invalid.');
        }
    }
}
