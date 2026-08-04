<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

use InvalidArgumentException;
use Stringable;

final readonly class PackagePath implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $path): self
    {
        if ($path === '' || strlen($path) > 512 || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new InvalidArgumentException('The package path is empty, too long, or contains an unsafe character.');
        }

        if ($path[0] === '/' || preg_match('/^[A-Za-z]:/D', $path) === 1) {
            throw new InvalidArgumentException('A package path must be relative.');
        }

        $segments = explode('/', rtrim($path, '/'));

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 191) {
                throw new InvalidArgumentException('A package path contains an unsafe segment.');
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
                throw new InvalidArgumentException('A package path cannot contain control characters.');
            }
        }

        return new self(implode('/', $segments));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
