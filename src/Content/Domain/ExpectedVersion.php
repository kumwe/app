<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Domain;

use InvalidArgumentException;

final readonly class ExpectedVersion
{
    public function __construct(private int $value)
    {
        if ($value < 1) {
            throw new InvalidArgumentException('An expected version must be at least one.');
        }
    }

    public function value(): int
    {
        return $this->value;
    }

    public function assertMatches(int $actual): void
    {
        if ($this->value !== $actual) {
            throw new VersionConflict($this->value, $actual);
        }
    }
}
