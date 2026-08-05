<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Extension\Application\ExtensionServiceProvider;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use RuntimeException;

final readonly class ExtensionRuntimeLoader
{
    public function __construct(private string $mapFile, private string $extensionRoot)
    {
    }

    /** @param array<string, object> $allowedServices */
    public function load(array $allowedServices): ActiveExtensionSet
    {
        $active = new ActiveExtensionSet();

        if (!is_file($this->mapFile)) {
            return $active;
        }

        try {
            $map = json_decode((string) file_get_contents($this->mapFile), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The compiled extension runtime map is invalid.', 0, $exception);
        }

        if (!is_array($map) || !is_array($map['extensions'] ?? null) || !array_is_list($map['extensions'])) {
            throw new RuntimeException('The compiled extension runtime map has an invalid structure.');
        }

        foreach ($map['extensions'] as $extension) {
            if (!is_array($extension) || array_is_list($extension)) {
                throw new RuntimeException('A compiled extension entry is invalid.');
            }

            $providerClass = $extension['provider'] ?? null;
            $identifier = $extension['identifier'] ?? null;
            $relativeRoot = $extension['root'] ?? null;
            $autoload = $extension['autoload'] ?? null;
            $type = $extension['type'] ?? null;

            if (
                !is_string($providerClass)
                || !is_string($identifier)
                || !is_string($relativeRoot)
                || !is_array($autoload)
                || !is_string($type)
            ) {
                throw new RuntimeException('A compiled extension entry is incomplete.');
            }

            $identifier = ExtensionIdentifier::fromString($identifier)->value();

            $root = $this->safeRoot($relativeRoot);
            $this->registerAutoload($root, $autoload);

            if (!class_exists($providerClass)) {
                throw new RuntimeException(sprintf('Active extension provider %s cannot be loaded.', $providerClass));
            }

            $provider = new $providerClass();

            if (!$provider instanceof ExtensionServiceProvider) {
                throw new RuntimeException(sprintf(
                    'Active extension provider %s must implement %s.',
                    $providerClass,
                    ExtensionServiceProvider::class,
                ));
            }

            $container = new RestrictedExtensionContainer($identifier, $allowedServices);
            $provider->register($container);
            $templatePath = $type === 'template' && is_dir($root . '/templates')
                ? $root . '/templates'
                : null;
            $active->add($identifier, $provider, $container, $templatePath);
        }

        $active->boot();

        return $active;
    }

    private function safeRoot(string $relativeRoot): string
    {
        if (
            preg_match('#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*/[0-9A-Za-z.+-]+$#D', $relativeRoot) !== 1
            || str_contains($relativeRoot, '..')
        ) {
            throw new InvalidArgumentException('The compiled extension root is unsafe.');
        }

        $root = $this->extensionRoot . '/' . $relativeRoot;
        $resolvedRoot = realpath($root);
        $resolvedExtensions = realpath($this->extensionRoot);

        if (
            !is_string($resolvedRoot)
            || !is_string($resolvedExtensions)
            || !str_starts_with($resolvedRoot . '/', $resolvedExtensions . '/')
        ) {
            throw new RuntimeException('An active extension root is missing or escapes extension storage.');
        }

        return $resolvedRoot;
    }

    /** @param array<mixed> $autoload */
    private function registerAutoload(string $root, array $autoload): void
    {
        foreach ($autoload as $prefix => $relativePath) {
            if (!is_string($prefix) || !is_string($relativePath)) {
                throw new RuntimeException('A compiled extension autoload entry is invalid.');
            }

            $base = $root . '/' . rtrim($relativePath, '/');

            spl_autoload_register(static function (string $class) use ($prefix, $base): void {
                if (!str_starts_with($class, $prefix)) {
                    return;
                }

                $relativeClass = substr($class, strlen($prefix));
                $file = $base . '/' . str_replace('\\', '/', $relativeClass) . '.php';

                if (is_file($file)) {
                    require $file;
                }
            });
        }
    }
}
