<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use DateInterval;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

/**
 * Time-boxed, owner-bound hold a worker has on a reserved job.
 *
 * The lease is what entitles one worker, and only that worker, to complete, retry, or extend the job
 * it claimed: `JobEnvelope` routes each of those transitions through the ownership and expiry checks
 * here first. Expiry is wall-clock, so a worker that dies mid-job gives up its hold without having to
 * cooperate, and the owner comparison uses `hash_equals` so the check does not leak by timing.
 *
 * @since  2.0.0
 */
final readonly class JobLease
{
    /**
     * Record a worker's hold on a job for a bounded window.
     *
     * @param   string             $owner       Identifier of the worker taking the job.
     * @param   DateTimeImmutable  $acquiredAt  Moment the hold began.
     * @param   DateTimeImmutable  $expiresAt   Moment the hold lapses; must be later than acquisition.
     *
     * @throws  InvalidArgumentException  When the owner is not a valid worker identifier, or the window is empty.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $owner,
        private DateTimeImmutable $acquiredAt,
        private DateTimeImmutable $expiresAt,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $owner) !== 1) {
            throw new InvalidArgumentException('The job lease owner is invalid.');
        }

        if ($expiresAt <= $acquiredAt) {
            throw new InvalidArgumentException('A job lease must expire after it is acquired.');
        }
    }

    /**
     * Identify the worker entitled to act on the leased job.
     *
     * @return  string  Worker identifier recorded when the hold was taken.
     *
     * @since   2.0.0
     */
    public function owner(): string
    {
        return $this->owner;
    }

    /**
     * Report when the hold began.
     *
     * @return  DateTimeImmutable  Instant of the original claim, carried unchanged through renewals.
     *
     * @since   2.0.0
     */
    public function acquiredAt(): DateTimeImmutable
    {
        return $this->acquiredAt;
    }

    /**
     * Report when the hold lapses.
     *
     * @return  DateTimeImmutable  Instant from which the job may be reaped from this worker.
     *
     * @since   2.0.0
     */
    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Decide whether the hold has lapsed at a given moment.
     *
     * @param   DateTimeImmutable  $time  Moment to test, normally the caller's current clock reading.
     *
     * @return  bool  True from the expiry instant onwards; the expiry moment itself already counts as expired.
     *
     * @since   2.0.0
     */
    public function isExpiredAt(DateTimeImmutable $time): bool
    {
        return $time >= $this->expiresAt;
    }

    /**
     * Prove that a worker still holds this lease before it is allowed to act on the job.
     *
     * @param   string             $owner  Worker claiming the hold.
     * @param   DateTimeImmutable  $time   Moment the action is attempted.
     *
     * @return  void
     *
     * @throws  DomainException  When the worker is not the owner, or the hold has already lapsed.
     *
     * @since   2.0.0
     */
    public function assertActiveOwner(string $owner, DateTimeImmutable $time): void
    {
        if (!hash_equals($this->owner, $owner)) {
            throw new DomainException('The worker does not own this job lease.');
        }

        if ($this->isExpiredAt($time)) {
            throw new DomainException('The job lease has expired.');
        }
    }

    /**
     * Extend an active hold, measured from the renewal moment rather than from the current expiry.
     *
     * @param   string             $owner         Worker asking to keep the job; must be the current owner.
     * @param   DateTimeImmutable  $time          Moment of the request, from which the new window runs.
     * @param   int                $leaseSeconds  Length of the new window in seconds; at least one.
     *
     * @return  self  A lease with the original acquisition instant and the later expiry.
     *
     * @throws  InvalidArgumentException  When fewer than one second of hold is requested.
     * @throws  DomainException  When the caller is not the owner, or the hold has already lapsed.
     *
     * @since   2.0.0
     */
    public function renew(string $owner, DateTimeImmutable $time, int $leaseSeconds): self
    {
        if ($leaseSeconds < 1) {
            throw new InvalidArgumentException('A renewed lease must last at least one second.');
        }
        $this->assertActiveOwner($owner, $time);

        return new self(
            $this->owner,
            $this->acquiredAt,
            $time->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))),
        );
    }
}
