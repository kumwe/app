<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use DateInterval;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

final readonly class JobEnvelope
{
    /** @var array<string, mixed> */
    private array $payload;

    /**
     * @param array<string, mixed> $payload
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
     * @param array<string, mixed> $payload
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

    public function id(): string
    {
        return $this->id;
    }

    public function queue(): string
    {
        return $this->queue;
    }

    public function type(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function status(): JobStatus
    {
        return $this->status;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function maximumAttempts(): int
    {
        return $this->maximumAttempts;
    }

    public function availableAt(): DateTimeImmutable
    {
        return $this->availableAt;
    }

    public function lease(): ?JobLease
    {
        return $this->lease;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isClaimableAt(DateTimeImmutable $time): bool
    {
        return $this->status === JobStatus::PENDING
            && $this->availableAt <= $time
            && $this->attempts < $this->maximumAttempts;
    }

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

    public function complete(string $worker, DateTimeImmutable $time): self
    {
        $this->assertActiveLease($worker, $time);

        return $this->transition(JobStatus::COMPLETED, $this->availableAt, $this->attempts, null);
    }

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

    private function assertActiveLease(string $worker, DateTimeImmutable $time): void
    {
        if ($this->status !== JobStatus::RESERVED || $this->lease === null) {
            throw new DomainException('The job is not reserved.');
        }

        $this->lease->assertActiveOwner($worker, $time);
    }

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
