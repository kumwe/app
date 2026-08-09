<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain\StepUp;

/**
 * Credential kind that satisfied one step-up request.
 *
 * @since  2.0.0
 */
enum StepUpMethod: string
{
    /**
     * A time-based one-time password from the enrolled authenticator.
     *
     * @since  2.0.0
     */
    case Totp = 'totp';

    /**
     * A previously unspent recovery code.
     *
     * @since  2.0.0
     */
    case RecoveryCode = 'recovery_code';
}
