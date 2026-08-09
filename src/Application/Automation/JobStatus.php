<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

/**
 * Lifecycle state of a queued job, as recorded in the status column of the jobs table.
 *
 * `JobEnvelope` is the state machine that moves a job between these values and rejects the combinations
 * that must not exist, while a `JobQueue` implementation writes and reads the backing strings. Only
 * `PENDING` and `RESERVED` are working states, and a job cycles between them for as long as it keeps
 * failing transiently; the other three are where it comes to rest, and nothing but an operator retry
 * moves a job back out of `DEAD`.
 *
 * @since  2.0.0
 */
enum JobStatus: string
{
    /**
     * Waiting in the queue, claimable once its availability moment has passed.
     *
     * A transient failure returns the job here with a later availability moment rather than burying it.
     *
     * @since  2.0.0
     */
    case PENDING = 'pending';

    /**
     * Held by one worker under a fenced lease; the only state that carries a lease.
     *
     * The lease is what makes losing the holder safe: a worker that dies leaves the lease to expire, and
     * the next claim reaps the job rather than leaving it stranded.
     *
     * @since  2.0.0
     */
    case RESERVED = 'reserved';

    /**
     * The attempt succeeded; the job stays on record and is never claimed again.
     *
     * @since  2.0.0
     */
    case COMPLETED = 'completed';

    /**
     * Buried after a permanent failure or after exhausting its attempts, awaiting an operator decision.
     *
     * @since  2.0.0
     */
    case DEAD = 'dead';

    /**
     * Withdrawn by an operator while still pending, so no worker ever ran it.
     *
     * @since  2.0.0
     */
    case CANCELED = 'canceled';
}
