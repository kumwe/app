<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use InvalidArgumentException;
use Stringable;

final readonly class RecordCursor implements Stringable
{
    private const MAX_BYTES = 65_536;

    private function __construct(private string $token)
    {
    }

    public static function fromString(string $token): self
    {
        if (
            strlen($token) < 32 || strlen($token) > self::MAX_BYTES
            || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token) !== 1
        ) {
            throw new InvalidArgumentException('A business-record cursor is malformed or unbounded.');
        }

        return new self($token);
    }

    public function value(): string
    {
        return $this->token;
    }

    public function __toString(): string
    {
        return $this->token;
    }
}
