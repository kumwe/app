<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use RuntimeException;

/**
 * Signals that an authorization decision could not be written to the audit trail.
 *
 * `DenyByDefaultAuthorizationGateway` records every decision it reaches before acting on it. When the
 * recorder fails and the decision was an allow, the gateway raises this instead of proceeding, so a
 * permitted mutation never runs unobserved — an unavailable audit sink turns into a hard failure rather
 * than a silent gap in the trail. A failed recording of a denial is swallowed, because the denial
 * already stops the operation. The original recorder failure is attached as the previous exception; the
 * message deliberately carries no actor, resource, or credential detail.
 *
 * @since  2.0.0
 */
final class AuthorizationAuditUnavailable extends RuntimeException
{
    /**
     * Wrap the recorder failure that left an authorization decision unrecorded.
     *
     * @param  \Throwable  $previous  Failure raised by the decision recorder, kept for diagnostics.
     *
     * @since  2.0.0
     */
    public function __construct(\Throwable $previous)
    {
        parent::__construct('An authorization decision could not be recorded safely.', 0, $previous);
    }
}
