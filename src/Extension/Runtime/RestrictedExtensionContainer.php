<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use RuntimeException;

/**
 * Curated service surface for trusted in-process extensions.
 *
 * This is an API compatibility boundary, not a security sandbox. Untrusted
 * integrations must execute out of process through an authenticated adapter.
 */
final class RestrictedExtensionContainer implements ExtensionContainer
{
    /** @var array<string, object> */
    private array $services;

    /** @var array<string, callable(ExtensionContainer): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    private readonly string $extension;

    /** @param array<string, object> $allowedServices */
    public function __construct(string $extension, array $allowedServices)
    {
        $this->extension = ExtensionIdentifier::fromString($extension)->value();
        $this->services = $allowedServices;
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new RuntimeException(sprintf('Extension service %s is not allowlisted.', $id));
        }

        return $this->instances[$id] = ($this->factories[$id])($this);
    }

    public function share(string $id, callable $factory): void
    {
        $prefix = 'extension.' . str_replace('/', '.', $this->extension) . '.';
        if (!str_starts_with($id, $prefix) || isset($this->services[$id]) || isset($this->factories[$id])) {
            throw new InvalidArgumentException('Extension-local services require a unique namespaced identifier.');
        }
        $this->factories[$id] = $factory;
    }
}
