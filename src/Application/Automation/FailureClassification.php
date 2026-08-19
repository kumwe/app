<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

/**
 * Verdict on whether a failed job may be attempted again.
 *
 * `RetryPolicy` derives this from the thrown value and carries it on the `RetryDecision` it returns,
 * so whoever schedules the next attempt can tell a fault that will clear from one that will repeat
 * for the same payload. `PermanentFailure`, `LogicException` and `Error` are permanent; anything the
 * policy does not recognise is transient, because retrying costs less than discarding work over a
 * fault the policy has not been taught about yet.
 *
 * @since  2.0.0
 */
enum FailureClassification: string
{
    /**
     * The fault is expected to clear on its own, so the job may be attempted again within its attempt limit.
     *
     * @since  2.0.0
     */
    case TRANSIENT = 'transient';

    /**
     * The fault will recur for the same payload, so the job is failed without another attempt.
     *
     * @since  2.0.0
     */
    case PERMANENT = 'permanent';
}
