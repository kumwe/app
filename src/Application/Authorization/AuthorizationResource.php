<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

use InvalidArgumentException;

final readonly class AuthorizationResource
{
    private function __construct(private string $type, private string $identifier)
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,62}$/D', $type) !== 1) {
            throw new InvalidArgumentException('An authorization resource type must be a lowercase identifier.');
        }

        if ($identifier === '' || strlen($identifier) > 191 || preg_match('/[\x00-\x1F\x7F]/', $identifier) === 1) {
            throw new InvalidArgumentException('An authorization resource identifier is invalid.');
        }
    }

    public static function collection(string $type): self
    {
        return new self($type, '*');
    }

    public static function item(string $type, string $identifier): self
    {
        return new self($type, trim($identifier));
    }

    public function type(): string
    {
        return $this->type;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }
}
