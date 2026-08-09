<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use DateInterval;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

/**
 * Immutable state machine for one queued job, from creation through claim to completion or death.
 *
 * Every transition returns a fresh envelope through the private constructor, which re-runs the whole
 * invariant set, so combinations that must never exist — a reserved job without a lease, more attempts
 * than the maximum, availability before creation — cannot be represented at all. Worker-driven
 * transitions go through the lease first, which is what stops two workers from finishing the same job,
 * and the payload is proven canonically encodable at construction rather than at storage time.
 *
 * @since  2.0.0
 */
final readonly class JobEnvelope
{
    /**
     * Job arguments, proven canonically encodable before the envelope was allowed to exist.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    private array $payload;

    /**
     * Assemble a fully validated envelope; every factory and transition funnels back through here.
     *
     * @param   string                $id               Canonical UUID the job is known by.
     * @param   string                $queue            Name of the queue the job waits on.
     * @param   string                $type             Registered job type deciding which handler runs it.
     * @param   array<string, mixed>  $payload          Job arguments; must be canonical-JSON encodable.
     * @param   int                   $schemaVersion    Version of the payload contract, from one upwards.
     * @param   int                   $priority         Relative claim-order weight, between -100 and 100.
     * @param   JobStatus             $status           Lifecycle state this envelope represents.
     * @param   DateTimeImmutable     $availableAt      Earliest moment the job may be claimed.
     * @param   int                   $attempts         Claims made so far, never more than the maximum.
     * @param   int                   $maximumAttempts  Claims allowed before the job is declared dead.
     * @param   ?JobLease             $lease            Current hold; non-null exactly when reserved.
     * @param   DateTimeImmutable     $createdAt        Moment the job was enqueued.
     *
     * @throws  InvalidArgumentException  When an identifier, counter, window, or lease invariant is violated.
     *
     * @since   2.0.0
     */
    private function __construct(
        private string $id,
        private string $queue,
        private string $type,
        array $payload,
        private int $schemaVersion,
        private int $priority,
        private JobStatus $status,
        private DateTimeImmutable $availableAt,
        private int $attempts,
        private int $maximumAttempts,
        private ?JobLease $lease,
        private DateTimeImmutable $createdAt,
    ) {
        $isCanonicalUuid = preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
            $id,
        ) === 1;

        if (!$isCanonicalUuid) {
            throw new InvalidArgumentException('A job ID must be a canonical UUID.');
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $queue) !== 1) {
            throw new InvalidArgumentException('The job queue name is invalid.');
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]{2,127}$/D', $type) !== 1) {
            throw new InvalidArgumentException('The job type is invalid.');
        }

        if ($schemaVersion < 1 || $maximumAttempts < 1 || $attempts < 0 || $attempts > $maximumAttempts) {
            throw new InvalidArgumentException('The job attempt or schema version configuration is invalid.');
        }

        if ($priority < -100 || $priority > 100) {
            throw new InvalidArgumentException('Job priority must be between -100 and 100.');
        }

        if (($status === JobStatus::RESERVED) !== ($lease !== null)) {
            throw new InvalidArgumentException('Only a reserved job may hold a lease.');
        }

        if ($availableAt < $createdAt) {
            throw new InvalidArgumentException('A job cannot become available before it is created.');
        }

        CanonicalJson::encode($payload);
        $this->payload = $payload;
    }

    /**
     * Create a freshly enqueued job: pending, unclaimed, with no attempts spent.
     *
     * @param   string                $id               Canonical UUID to identify the job by.
     * @param   string                $queue            Name of the queue the job waits on.
     * @param   string                $type             Registered job type deciding which handler runs it.
     * @param   array<string, mixed>  $payload          Job arguments; must be canonical-JSON encodable.
     * @param   DateTimeImmutable     $availableAt      Earliest claim moment; not before creation.
     * @param   DateTimeImmutable     $createdAt        Moment the job is enqueued.
     * @param   int                   $schemaVersion    Version of the payload contract, from one upwards.
     * @param   int                   $priority         Relative claim-order weight, between -100 and 100.
     * @param   int                   $maximumAttempts  Claims allowed before the job is declared dead.
     *
     * @return  self  A pending envelope with no lease and zero attempts.
     *
     * @throws  InvalidArgumentException  When an argument violates a job invariant.
     *
     * @since   2.0.0
     */
    public static function pending(
        string $id,
        string $queue,
        string $type,
        array $payload,
        DateTimeImmutable $availableAt,
        DateTimeImmutable $createdAt,
        int $schemaVersion = 1,
        int $priority = 0,
        int $maximumAttempts = 5,
    ): self {
        return new self(
            $id,
            $queue,
            $type,
            $payload,
            $schemaVersion,
            $priority,
            JobStatus::PENDING,
            $availableAt,
            0,
            $maximumAttempts,
            null,
            $createdAt,
        );
    }

    /**
     * Identify the job.
     *
     * @return  string  Canonical UUID, carried unchanged through every transition.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Name the queue the job waits on.
     *
     * @return  string  Queue name a worker must be polling for this job to be claimed.
     *
     * @since   2.0.0
     */
    public function queue(): string
    {
        return $this->queue;
    }

    /**
     * Name the registered type that decides which handler executes the job.
     *
     * @return  string  Job type resolved against the handler registry at execution time.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Return the arguments handed to the handler.
     *
     * @return  array<string, mixed>  Payload as enqueued, to be read under the recorded schema version.
     *
     * @since   2.0.0
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Report which version of the payload contract the job was enqueued under.
     *
     * @return  int  Schema version, one or greater, letting a handler still read older jobs.
     *
     * @since   2.0.0
     */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    /**
     * Report the weight a queue uses to order this job against its peers.
     *
     * @return  int  Between -100 and 100; higher means the job is meant to be claimed sooner.
     *
     * @since   2.0.0
     */
    public function priority(): int
    {
        return $this->priority;
    }

    /**
     * Report where the job stands in its lifecycle.
     *
     * @return  JobStatus  Current state; only a reserved job carries a lease.
     *
     * @since   2.0.0
     */
    public function status(): JobStatus
    {
        return $this->status;
    }

    /**
     * Report how many times the job has been claimed.
     *
     * @return  int  Attempts spent; the count rises at claim time, not at completion.
     *
     * @since   2.0.0
     */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * Report the attempt budget the job is allowed.
     *
     * @return  int  Claims permitted in total; once spent, the next release buries the job.
     *
     * @since   2.0.0
     */
    public function maximumAttempts(): int
    {
        return $this->maximumAttempts;
    }

    /**
     * Report the earliest moment the job may be claimed.
     *
     * @return  DateTimeImmutable  Availability instant, moved forward whenever a retry is scheduled.
     *
     * @since   2.0.0
     */
    public function availableAt(): DateTimeImmutable
    {
        return $this->availableAt;
    }

    /**
     * Return the hold a worker currently has on the job.
     *
     * @return  ?JobLease  The live hold while reserved; null in every other state.
     *
     * @since   2.0.0
     */
    public function lease(): ?JobLease
    {
        return $this->lease;
    }

    /**
     * Report when the job was enqueued.
     *
     * @return  DateTimeImmutable  Creation instant, never later than the availability instant.
     *
     * @since   2.0.0
     */
    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Decide whether a worker may claim this job at a given moment.
     *
     * @param   DateTimeImmutable  $time  Moment to test, normally the claiming worker's clock reading.
     *
     * @return  bool  True only while pending, already available, and still inside the attempt budget.
     *
     * @since   2.0.0
     */
    public function isClaimableAt(DateTimeImmutable $time): bool
    {
        return $this->status === JobStatus::PENDING
            && $this->availableAt <= $time
            && $this->attempts < $this->maximumAttempts;
    }

    /**
     * Reserve the job for a worker and start its lease.
     *
     * The attempt is spent here rather than at completion, so a worker that dies without releasing has
     * already used one up; reaping its expired lease does not hand it back.
     *
     * @param   string             $worker        Identifier of the worker taking the job.
     * @param   DateTimeImmutable  $time          Moment of the claim, from which the lease runs.
     * @param   int                $leaseSeconds  Length of the initial hold in seconds; at least one.
     *
     * @return  self  A reserved envelope carrying a fresh lease and one more spent attempt.
     *
     * @throws  InvalidArgumentException  When the hold is under a second, or the worker identifier is invalid.
     * @throws  DomainException  When the job is not claimable at that moment.
     *
     * @since   2.0.0
     */
    public function claim(string $worker, DateTimeImmutable $time, int $leaseSeconds): self
    {
        if ($leaseSeconds < 1) {
            throw new InvalidArgumentException('A job lease must last at least one second.');
        }

        if (!$this->isClaimableAt($time)) {
            throw new DomainException('The job is not available to claim.');
        }

        return $this->transition(
            JobStatus::RESERVED,
            $this->availableAt,
            $this->attempts + 1,
            new JobLease($worker, $time, $time->add(new DateInterval(sprintf('PT%dS', $leaseSeconds)))),
        );
    }

    /**
     * Reap a job whose lease ran out, putting it back in the queue or burying it.
     *
     * This is the recovery path rather than the worker path, so it deliberately asks for no owner: the
     * whole point is to reclaim a job from a worker that is no longer there. The job becomes available
     * again from the reaping moment unless its attempt budget is already spent.
     *
     * @param   DateTimeImmutable  $time  Moment of the reap, which becomes the new availability instant.
     *
     * @return  self  A pending envelope with no lease, or a dead one when no attempts remain.
     *
     * @throws  DomainException  When the job is not reserved, or its lease has not expired yet.
     *
     * @since   2.0.0
     */
    public function releaseExpiredLease(DateTimeImmutable $time): self
    {
        if ($this->status !== JobStatus::RESERVED || $this->lease === null || !$this->lease->isExpiredAt($time)) {
            throw new DomainException('The job does not have an expired lease to release.');
        }

        if ($this->attempts >= $this->maximumAttempts) {
            return $this->transition(JobStatus::DEAD, $time, $this->attempts, null);
        }

        return $this->transition(JobStatus::PENDING, $time, $this->attempts, null);
    }

    /**
     * Hand a failed attempt back to the queue for a later retry, or bury it when the budget is spent.
     *
     * @param   string             $worker   Worker giving the job up; must still hold an active lease.
     * @param   DateTimeImmutable  $time     Moment of the release.
     * @param   DateTimeImmutable  $retryAt  Moment the job becomes claimable again; not before `$time`.
     *
     * @return  self  A pending envelope available from the retry instant, or a dead one when attempts ran out.
     *
     * @throws  DomainException  When the job is not reserved, or the worker's lease is not active.
     * @throws  InvalidArgumentException  When the retry is scheduled before the release moment.
     *
     * @since   2.0.0
     */
    public function releaseForRetry(
        string $worker,
        DateTimeImmutable $time,
        DateTimeImmutable $retryAt,
    ): self {
        $this->assertActiveLease($worker, $time);

        if ($retryAt < $time) {
            throw new InvalidArgumentException('A retry cannot be scheduled in the past.');
        }

        if ($this->attempts >= $this->maximumAttempts) {
            return $this->transition(JobStatus::DEAD, $time, $this->attempts, null);
        }

        return $this->transition(JobStatus::PENDING, $retryAt, $this->attempts, null);
    }

    /**
     * Record the job as finished by the worker holding its lease.
     *
     * @param   string             $worker  Worker reporting success; must hold an active lease.
     * @param   DateTimeImmutable  $time    Moment success is reported, used to test lease expiry.
     *
     * @return  self  A completed envelope with its lease released.
     *
     * @throws  DomainException  When the job is not reserved, or the worker's lease is not active.
     *
     * @since   2.0.0
     */
    public function complete(string $worker, DateTimeImmutable $time): self
    {
        $this->assertActiveLease($worker, $time);

        return $this->transition(JobStatus::COMPLETED, $this->availableAt, $this->attempts, null);
    }

    /**
     * Extend the current hold so a still-working worker is not reaped mid-job.
     *
     * The job stays reserved, its availability instant and attempt count untouched; only the lease
     * expiry moves, and only for the worker that already owns it.
     *
     * @param   string             $worker        Worker asking to keep the job; must own the lease.
     * @param   DateTimeImmutable  $time          Moment of the request, from which the new window runs.
     * @param   int                $leaseSeconds  Length of the new window in seconds; at least one.
     *
     * @return  self  A reserved envelope whose lease expires later.
     *
     * @throws  DomainException  When the job is not reserved, or the worker's lease is not active.
     * @throws  InvalidArgumentException  When fewer than one second of hold is requested.
     *
     * @since   2.0.0
     */
    public function renewLease(string $worker, DateTimeImmutable $time, int $leaseSeconds): self
    {
        if ($this->status !== JobStatus::RESERVED || $this->lease === null) {
            throw new DomainException('The job is not reserved.');
        }

        return $this->transition(
            JobStatus::RESERVED,
            $this->availableAt,
            $this->attempts,
            $this->lease->renew($worker, $time, $leaseSeconds),
        );
    }

    /**
     * Guard a worker-driven transition by proving the caller still holds the lease.
     *
     * @param   string             $worker  Worker attempting the transition.
     * @param   DateTimeImmutable  $time    Moment of the attempt.
     *
     * @return  void
     *
     * @throws  DomainException  When the job is not reserved, or the lease is foreign or expired.
     *
     * @since   2.0.0
     */
    private function assertActiveLease(string $worker, DateTimeImmutable $time): void
    {
        if ($this->status !== JobStatus::RESERVED || $this->lease === null) {
            throw new DomainException('The job is not reserved.');
        }

        $this->lease->assertActiveOwner($worker, $time);
    }

    /**
     * Copy the envelope with new lifecycle fields, revalidating every invariant on the way.
     *
     * @param   JobStatus          $status       Lifecycle state to move to.
     * @param   DateTimeImmutable  $availableAt  Availability instant the new envelope carries.
     * @param   int                $attempts     Attempt count the new envelope carries.
     * @param   ?JobLease          $lease        Hold to carry, which must be null unless reserved.
     *
     * @return  self  A new envelope; everything but status, availability, attempts, and lease is carried over.
     *
     * @throws  InvalidArgumentException  When the resulting combination would break a job invariant.
     *
     * @since   2.0.0
     */
    private function transition(
        JobStatus $status,
        DateTimeImmutable $availableAt,
        int $attempts,
        ?JobLease $lease,
    ): self {
        return new self(
            $this->id,
            $this->queue,
            $this->type,
            $this->payload,
            $this->schemaVersion,
            $this->priority,
            $status,
            $availableAt,
            $attempts,
            $this->maximumAttempts,
            $lease,
            $this->createdAt,
        );
    }
}
