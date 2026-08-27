<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

/**
 * The measured phase durations of one committed aggregate document command.
 *
 * P4-B requires the commit to expose validation, lock-wait, write, revision, audit, event and total
 * durations, so a characterisation run can say where a slow commit spent its time instead of guessing.
 * The named phases are components, not a partition: work outside them — the idempotency ledger, policy
 * planning, fingerprinting — belongs to the total alone, so the phases sum to less than the total rather
 * than pretending to account for everything.
 *
 * @since  2.0.0
 */
final readonly class DocumentCommitTimings
{
    /**
     * Capture one commit's phase durations, all in milliseconds of wall time.
     *
     * @param  float  $validationMs  Preparing and validating the header and the whole collection,
     *         excluding the lock waits incurred while doing so.
     * @param  float  $lockWaitMs    Acquiring definition fences and sequence-counter row locks.
     * @param  float  $writeMs       Writing the header row and the owned-line collection, excluding the
     *         lock waits incurred while doing so.
     * @param  float  $revisionMs    Appending the revision snapshot.
     * @param  float  $auditMs       Recording the audit event.
     * @param  float  $eventMs       Publishing the synchronous listeners and the durable outbox event.
     * @param  float  $totalMs       The whole command, from entry to committed result.
     *
     * @since  2.0.0
     */
    public function __construct(
        public float $validationMs,
        public float $lockWaitMs,
        public float $writeMs,
        public float $revisionMs,
        public float $auditMs,
        public float $eventMs,
        public float $totalMs,
    ) {
    }

    /**
     * The phase durations keyed by their contract names, ready for a report or an assertion.
     *
     * @return  array<string, float>  Milliseconds keyed by phase name, `total` included.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'validation' => $this->validationMs,
            'lock_wait' => $this->lockWaitMs,
            'write' => $this->writeMs,
            'revision' => $this->revisionMs,
            'audit' => $this->auditMs,
            'event' => $this->eventMs,
            'total' => $this->totalMs,
        ];
    }
}
