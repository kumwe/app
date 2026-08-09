<?php

declare(strict_types=1);

namespace Kumwe\CMS\Portal\Application;

use InvalidArgumentException;

/**
 * Newly created portal session paired with the opaque cookie token disclosed exactly once.
 *
 * @since  2.0.0
 */
final readonly class CreatedPortalSession
{
    /**
     * Validate the one-time browser token.
     *
     * @param   PortalSession  $session      Persisted session metadata.
     * @param   string         $cookieToken  High-entropy opaque browser credential.
     *
     * @throws  InvalidArgumentException  When the token is too short or oversized.
     *
     * @since   2.0.0
     */
    public function __construct(
        public PortalSession $session,
        public string $cookieToken,
    ) {
        if (
            strlen($cookieToken) < 43
            || strlen($cookieToken) > 512
            || preg_match('/^[A-Za-z0-9_-]+$/D', $cookieToken) !== 1
        ) {
            throw new InvalidArgumentException('A portal cookie token is invalid.');
        }
    }
}
