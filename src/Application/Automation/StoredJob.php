<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use InvalidArgumentException;

/**
 * One reserved queue row, carrying the fencing token that proves the reservation is still this worker's.
 *
 * `JobQueue::claim()` builds this from the row it reserved and the worker hands the same instance back
 * to `renew()`, `complete()` and `fail()`. Each of those writes matches on the lease token as well as
 * the identifier, so a worker whose lease expired and whose job a sibling has re-claimed can no longer
 * move the row — which is what makes an expired lease safe to reap. Construction rejects a token that
 * is not a canonical UUID and an execution class that is not a known one, so a row with a missing or
 * hand-edited lease or scope column never reaches a handler.
 *
 * @since  2.0.0
 */
final readonly class StoredJob
{
    /**
     * Capture a reserved queue row together with the lease it was reserved under.
     *
     * @param   string                $id               Identifier of the reserved job row, a UUID version 7.
     * @param   string                $queue            Queue name the row was claimed from.
     * @param   string                $type             Registered job type naming the handler that runs it.
     * @param   array<string, mixed>  $payload          Decoded handler arguments, keyed by argument name.
     * @param   int                   $schemaVersion    Version the payload was written under.
     * @param   int                   $attempts         Attempts including this one, counted from one.
     * @param   int                   $maximumAttempts  Attempt count at which a failure dead-letters the job.
     * @param   string                $leaseToken       Fencing token this claim stamped on the row.
     * @param   string                $executionClass   Backing value of the row's `JobExecutionClass`.
     *
     * @throws  InvalidArgumentException  When the lease token is not a canonical UUID, or the execution
     *          class is not a known `JobExecutionClass` value.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public string $queue,
        public string $type,
        public array $payload,
        public int $schemaVersion,
        public int $attempts,
        public int $maximumAttempts,
        public string $leaseToken,
        public string $executionClass = JobExecutionClass::Site->value,
    ) {
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
                $leaseToken,
            ) !== 1
        ) {
            throw new InvalidArgumentException('A stored job requires a canonical lease fencing token.');
        }
        if (JobExecutionClass::tryFrom($this->executionClass) === null) {
            throw new InvalidArgumentException('A stored job execution class is invalid.');
        }
    }
}
