<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Security;

use RuntimeException;

/**
 * Raised when an operation demands a fresh proof of the actor's password and does not get one.
 *
 * A handful of business-schema operations stay dangerous however the session was established —
 * approving a high-risk schema plan, planning a purge, recording clean-target recovery evidence — so
 * `HighImpactCredentialGuard` re-checks the current password on top of the ordinary capability
 * decision. This is the single failure that check raises, covering both a context with no human
 * principal behind it and a password that does not verify. Keeping it distinct from
 * `AuthorizationDenied` is what lets a client tell the two apart: `ProblemDetailsMiddleware` answers
 * both with a 403, but gives this one its own problem type, so a caller knows to re-prompt for the
 * password rather than to report the operation as forbidden.
 *
 * @since  2.0.0
 */
final class HighImpactAuthenticationRequired extends RuntimeException
{
}
