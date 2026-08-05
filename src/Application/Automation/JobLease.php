<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use DateInterval;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

final readonly class JobLease
{
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

    public function owner(): string
    {
        return $this->owner;
    }

    public function acquiredAt(): DateTimeImmutable
    {
        return $this->acquiredAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpiredAt(DateTimeImmutable $time): bool
    {
        return $time >= $this->expiresAt;
    }

    public function assertActiveOwner(string $owner, DateTimeImmutable $time): void
    {
        if (!hash_equals($this->owner, $owner)) {
            throw new DomainException('The worker does not own this job lease.');
        }

        if ($this->isExpiredAt($time)) {
            throw new DomainException('The job lease has expired.');
        }
    }

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
