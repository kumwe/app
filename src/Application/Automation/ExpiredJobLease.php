<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use RuntimeException;

/**
 * Failure recorded for a job whose final worker lease expired before the job completed.
 *
 * A worker that dies mid-job never reports; what the queue can observe is that the lease it granted has
 * run out while the job is still marked running. When that happens on the last permitted attempt the
 * queue buries the job itself, and the failed-job row it writes names this type as the failure, so an
 * operator reading the dead-letter screen sees the same vocabulary a handler-raised failure carries
 * instead of a label that resolves to nothing. Nothing in the process throws it today — the queue detects
 * the condition after the fact — but a worker that discovers its own lease has lapsed may raise it to say
 * why it abandoned the work.
 *
 * @since  2.0.0
 */
final class ExpiredJobLease extends RuntimeException
{
}
