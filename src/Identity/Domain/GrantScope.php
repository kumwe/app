<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain;

use InvalidArgumentException;

final readonly class GrantScope
{
    private const GLOBAL_TYPE = 'global';
    private const MAX_LENGTH = 191;

    private function __construct(
        private string $type,
        private ?string $identifier,
    ) {
    }

    public static function global(): self
    {
        return new self(self::GLOBAL_TYPE, null);
    }

    public static function named(string $type, string $identifier): self
    {
        $type = strtolower(trim($type));
        $identifier = trim($identifier);

        if ($type === self::GLOBAL_TYPE) {
            throw new InvalidArgumentException('The global grant scope cannot have an identifier.');
        }

        if (preg_match('/^[a-z][a-z0-9._-]{0,62}$/D', $type) !== 1) {
            throw new InvalidArgumentException('A grant scope type must be a valid lowercase identifier.');
        }

        if ($identifier === '' || strlen($identifier) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A grant scope identifier must contain between 1 and 191 characters.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $identifier) === 1) {
            throw new InvalidArgumentException('A grant scope identifier cannot contain control characters.');
        }

        return new self($type, $identifier);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function identifier(): ?string
    {
        return $this->identifier;
    }

    public function isGlobal(): bool
    {
        return $this->type === self::GLOBAL_TYPE;
    }

    public function covers(self $requested): bool
    {
        return $this->isGlobal()
            || ($this->type === $requested->type && $this->identifier === $requested->identifier);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->identifier === $other->identifier;
    }
}
