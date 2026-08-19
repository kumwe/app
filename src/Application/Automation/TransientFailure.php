<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

/**
 * Marker a job failure carries to say that repeating the same job could still succeed.
 *
 * `RetryPolicy` already treats an unrecognised failure as transient, so this exists for the one case
 * that would otherwise be classified the other way: a failure that extends `LogicException` or `Error`
 * is read as a programming defect and retired permanently. An exception raised for a lost connection,
 * a lock timeout, or a busy upstream that happens to sit under those hierarchies implements this so
 * the job is released for another attempt instead of being dead-lettered. Pushing a failure the other
 * way needs no marker at all: throwing `PermanentFailure` stops the retries outright.
 *
 * @since  2.0.0
 */
interface TransientFailure
{
}
