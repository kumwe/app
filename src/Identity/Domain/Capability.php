<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain;

use InvalidArgumentException;
use Stringable;

final readonly class Capability implements Stringable
{
    private const MAX_LENGTH = 191;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));

        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A capability must contain between 1 and 191 characters.');
        }

        if (preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+)*$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                'A capability must be a lowercase, delimiter-separated identifier.',
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
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
