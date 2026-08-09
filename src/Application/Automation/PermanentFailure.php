<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use RuntimeException;

/**
 * Failure a job handler raises to say the work must not be attempted again.
 *
 * `Worker` catches whatever the handler throws and passes `instanceof PermanentFailure` to the queue as
 * the permanence flag, so a job raising this is buried at once instead of being backed off through the
 * attempts it has left; `RetryPolicy` classifies it the same way. Reach for it when the payload itself is
 * the fault — a missing field, a record that no longer exists — because an identical retry would fail
 * identically and only delay the operator seeing it. The worker raises it itself when a claimed job's
 * type has no registered handler.
 *
 * @since  2.0.0
 */
final class PermanentFailure extends RuntimeException
{
}
