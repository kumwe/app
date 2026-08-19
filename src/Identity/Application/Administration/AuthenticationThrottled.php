<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Administration;

use RuntimeException;

/**
 * Refusal raised when an account and origin pair has spent its budget of failed authentication attempts.
 *
 * `AuthenticationRateLimiter` implementations raise this before a stored password hash is read, so a
 * throttled caller learns nothing about whether the account exists; `DoctrineAdministratorIdentityGateway`
 * and `DoctrineThemeActivationGuard` let it travel outward untouched. Every delivery adapter recognises
 * it by type rather than by message and all answer `429` with a `Retry-After` hint:
 * `ProblemDetailsMiddleware` emits the `urn:kumwe:problem:authentication-throttled` problem document, and
 * the administrator login handler re-renders the sign-in form carrying the message.
 *
 * @since  2.0.0
 */
final class AuthenticationThrottled extends RuntimeException
{
    /**
     * Build the refusal with the fixed operator-facing message every throttled attempt shares.
     *
     * The message is deliberately constant and free of account detail, because it is shown to a caller
     * who has not authenticated and must not be able to probe for valid accounts.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('Too many unsuccessful authentication attempts. Try again later.');
    }
}
