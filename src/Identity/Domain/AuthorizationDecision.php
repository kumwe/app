<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain;

use InvalidArgumentException;

final readonly class AuthorizationDecision
{
    private function __construct(
        private bool $allowed,
        private string $reason,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{0,126}$/D', $reason) !== 1) {
            throw new InvalidArgumentException('An authorization reason must be a stable lowercase identifier.');
        }
    }

    public static function allow(string $reason = 'policy.allowed'): self
    {
        return new self(true, $reason);
    }

    public static function deny(string $reason = 'policy.denied'): self
    {
        return new self(false, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function isDenied(): bool
    {
        return !$this->allowed;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
