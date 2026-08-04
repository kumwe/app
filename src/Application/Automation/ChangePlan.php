<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use DateInterval;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

final readonly class ChangePlan
{
    private const int CONFIRMATION_DIGEST_LENGTH = 16;

    /** @var array<string, mixed> */
    private array $payload;
    private string $digest;

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        private string $id,
        private string $command,
        array $payload,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $expiresAt,
        private ConfirmationRequirement $confirmationRequirement,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('A change plan ID is required.');
        }

        if (!preg_match('/^[A-Za-z][A-Za-z0-9._:-]{2,127}$/D', $command)) {
            throw new InvalidArgumentException('The change plan command name is invalid.');
        }

        if ($expiresAt <= $createdAt) {
            throw new InvalidArgumentException('A change plan must expire after it is created.');
        }

        $this->payload = $payload;
        $this->digest = CanonicalJson::digest([
            'command' => $command,
            'payload' => $payload,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function create(
        string $id,
        string $command,
        array $payload,
        DateTimeImmutable $createdAt,
        int $ttlSeconds,
        ConfirmationRequirement $confirmationRequirement = ConfirmationRequirement::NONE,
    ): self {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('A change plan TTL must be at least one second.');
        }

        return new self(
            $id,
            $command,
            $payload,
            $createdAt,
            $createdAt->add(new DateInterval(sprintf('PT%dS', $ttlSeconds))),
            $confirmationRequirement,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function command(): string
    {
        return $this->command;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function digest(): string
    {
        return $this->digest;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function requiresConfirmation(): bool
    {
        return $this->confirmationRequirement === ConfirmationRequirement::EXPLICIT;
    }

    public function confirmationToken(): ?string
    {
        if (!$this->requiresConfirmation()) {
            return null;
        }

        return 'confirm-' . substr($this->digest, 0, self::CONFIRMATION_DIGEST_LENGTH);
    }

    public function isExpiredAt(DateTimeImmutable $time): bool
    {
        return $time >= $this->expiresAt;
    }

    public function assertCanApply(
        string $commandDigest,
        DateTimeImmutable $time,
        ?string $confirmationToken = null,
    ): void {
        if ($this->isExpiredAt($time)) {
            throw new DomainException('The change plan has expired.');
        }

        if (!hash_equals($this->digest, strtolower($commandDigest))) {
            throw new DomainException('The command no longer matches the change plan.');
        }

        $expectedConfirmation = $this->confirmationToken();

        if ($expectedConfirmation !== null && !hash_equals($expectedConfirmation, (string) $confirmationToken)) {
            throw new DomainException('Explicit confirmation is required to apply this change plan.');
        }
    }
}
