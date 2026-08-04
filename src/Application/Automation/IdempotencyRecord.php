<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

final readonly class IdempotencyRecord
{
    private function __construct(
        private IdempotencyKey $key,
        private string $subject,
        private string $operation,
        private string $requestDigest,
        private IdempotencyState $state,
        private ?IdempotencyResult $result,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $expiresAt,
    ) {
        if (trim($subject) === '' || trim($operation) === '') {
            throw new InvalidArgumentException('Idempotency subject and operation are required.');
        }

        if (!preg_match('/^[a-f0-9]{64}$/D', $requestDigest)) {
            throw new InvalidArgumentException('The idempotency request digest must be SHA-256.');
        }

        if ($expiresAt <= $createdAt) {
            throw new InvalidArgumentException('An idempotency record must expire after it is created.');
        }

        if (($state === IdempotencyState::COMPLETED) !== ($result !== null)) {
            throw new InvalidArgumentException('Only completed idempotency records may contain a result.');
        }
    }

    public static function begin(
        IdempotencyKey $key,
        string $subject,
        string $operation,
        mixed $request,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
    ): self {
        return new self(
            $key,
            $subject,
            $operation,
            CanonicalJson::digest($request),
            IdempotencyState::IN_PROGRESS,
            null,
            $createdAt,
            $expiresAt,
        );
    }

    public function key(): IdempotencyKey
    {
        return $this->key;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function requestDigest(): string
    {
        return $this->requestDigest;
    }

    public function state(): IdempotencyState
    {
        return $this->state;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpiredAt(DateTimeImmutable $time): bool
    {
        return $time >= $this->expiresAt;
    }

    public function assertRequestMatches(mixed $request): void
    {
        if (!hash_equals($this->requestDigest, CanonicalJson::digest($request))) {
            throw new DomainException('The idempotency key has already been used for a different request.');
        }
    }

    public function complete(IdempotencyResult $result): self
    {
        if ($this->state !== IdempotencyState::IN_PROGRESS) {
            throw new DomainException('Only an in-progress idempotency record can be completed.');
        }

        return new self(
            $this->key,
            $this->subject,
            $this->operation,
            $this->requestDigest,
            IdempotencyState::COMPLETED,
            $result,
            $this->createdAt,
            $this->expiresAt,
        );
    }

    public function fail(): self
    {
        if ($this->state !== IdempotencyState::IN_PROGRESS) {
            throw new DomainException('Only an in-progress idempotency record can fail.');
        }

        return new self(
            $this->key,
            $this->subject,
            $this->operation,
            $this->requestDigest,
            IdempotencyState::FAILED,
            null,
            $this->createdAt,
            $this->expiresAt,
        );
    }

    public function replay(mixed $request, DateTimeImmutable $time): IdempotencyResult
    {
        $this->assertRequestMatches($request);

        if ($this->isExpiredAt($time)) {
            throw new DomainException('The idempotency record has expired.');
        }

        if ($this->state === IdempotencyState::IN_PROGRESS) {
            throw new DomainException('The idempotent operation is still in progress.');
        }

        if ($this->state === IdempotencyState::FAILED || $this->result === null) {
            throw new DomainException('The idempotent operation did not complete successfully.');
        }

        return $this->result;
    }
}
