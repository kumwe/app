<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use InvalidArgumentException;
use Stringable;

final readonly class IdempotencyKey implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                'An idempotency key must contain 8 to 128 transport-safe ASCII characters.',
            );
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
