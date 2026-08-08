<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final readonly class BusinessRecordIdempotency
{
    /** @var array<string, mixed>|null */
    private ?array $result;

    /** @param array<string, mixed>|null $result */
    public function __construct(
        public string $id,
        public string $scopeDigest,
        public string $siteIdentifier,
        public ?string $organizationIdentifier,
        public string $actorId,
        public string $operation,
        public string $operationId,
        public string $requestFingerprint,
        public string $authorizationFingerprint,
        public BusinessRecordIdempotencyState $state,
        ?array $result,
        public ?string $resultChecksum,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $completedAt,
        public DateTimeImmutable $expiresAt,
    ) {
        if (!Uuid::isValid($id)) {
            throw new InvalidArgumentException('A business-record idempotency ID must be a canonical UUID.');
        }
        foreach ([$scopeDigest, $requestFingerprint, $authorizationFingerprint] as $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new InvalidArgumentException('A business-record idempotency fingerprint is invalid.');
            }
        }
        if ($resultChecksum !== null && preg_match('/^[a-f0-9]{64}$/D', $resultChecksum) !== 1) {
            throw new InvalidArgumentException('A business-record idempotency result checksum is invalid.');
        }
        if (
            preg_match('/^[a-z][a-z0-9._:-]{0,95}$/D', $operation) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $operationId) !== 1
        ) {
            throw new InvalidArgumentException('A business-record idempotency operation identity is invalid.');
        }
        if ($expiresAt <= $createdAt) {
            throw new InvalidArgumentException('A business-record idempotency entry must expire after creation.');
        }
        if (
            ($state === BusinessRecordIdempotencyState::Completed)
            !== ($result !== null && $resultChecksum !== null && $completedAt !== null)
        ) {
            throw new InvalidArgumentException('A business-record idempotency result state is inconsistent.');
        }
        $this->result = $result;
    }

    /** @return array<string, mixed>|null */
    public function result(): ?array
    {
        return $this->result;
    }

    public function matches(string $requestFingerprint, string $authorizationFingerprint): bool
    {
        return hash_equals($this->requestFingerprint, $requestFingerprint)
            && hash_equals($this->authorizationFingerprint, $authorizationFingerprint);
    }

    public function isCompleted(): bool
    {
        return $this->state === BusinessRecordIdempotencyState::Completed && $this->result !== null;
    }
}
