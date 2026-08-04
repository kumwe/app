<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain;

use InvalidArgumentException;
use Stringable;

final readonly class EmailAddress implements Stringable
{
    private const MAX_LENGTH = 254;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));

        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('An email address must contain between 1 and 254 characters.');
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The email address is invalid.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
