<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

use InvalidArgumentException;

final readonly class SiteContext
{
    public const DEFAULT = 'default';

    private function __construct(private string $identifier)
    {
    }

    public static function default(): self
    {
        return new self(self::DEFAULT);
    }

    public static function fromString(string $identifier): self
    {
        $identifier = strtolower(trim($identifier));

        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('A site context must be a valid non-empty identifier.');
        }

        return new self($identifier);
    }

    public function identifier(): string
    {
        return $this->identifier;
    }
}
