<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application;

use InvalidArgumentException;

final readonly class Asset
{
    /** @param list<string> $dependencies */
    public function __construct(
        private string $name,
        private string $path,
        private ?string $integrity = null,
        private array $dependencies = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]*$/D', $name) !== 1) {
            throw new InvalidArgumentException('An asset name must be a safe logical identifier.');
        }

        if (
            str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_contains($path, '//')
            || str_starts_with($path, '/')
            || str_contains($path, '?')
            || str_contains($path, '#')
            || preg_match('#(^|/)\.\.(/|$)#D', $path) === 1
            || preg_match('#(^|/)\.(/|$)#D', $path) === 1
            || preg_match('#^[a-z][a-z0-9+.-]*:#iD', $path) === 1
            || preg_match('#^[a-zA-Z0-9_./-]+$#D', $path) !== 1
        ) {
            throw new InvalidArgumentException('An asset path must be a safe relative path.');
        }

        if ($integrity !== null && preg_match('/^sha(?:256|384|512)-[A-Za-z0-9+\/=]+$/D', $integrity) !== 1) {
            throw new InvalidArgumentException('Asset integrity must be a valid SHA SRI value.');
        }

        if (!array_is_list($dependencies) || count($dependencies) !== count(array_unique($dependencies))) {
            throw new InvalidArgumentException('Asset dependencies must be unique.');
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function integrity(): ?string
    {
        return $this->integrity;
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return $this->dependencies;
    }
}
