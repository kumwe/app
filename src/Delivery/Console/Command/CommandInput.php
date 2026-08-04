<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use JsonException;

final class CommandInput
{
    /**
     * @param list<string> $arguments
     * @return array<string, string>
     */
    public static function options(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z][a-z0-9-]*)=(.*)$/D', $argument, $matches) !== 1) {
                throw new InvalidArgumentException('Command options must use --name=value syntax.');
            }
            $options[$matches[1]] = $matches[2];
        }
        return $options;
    }

    /** @param array<string, string> $options */
    public static function required(array $options, string $name): string
    {
        $value = trim($options[$name] ?? '');
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The --%s option is required.', $name));
        }
        return $value;
    }

    /** @param array<string, string> $options */
    public static function positiveInteger(array $options, string $name): int
    {
        $value = self::required($options, $name);
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The --%s option must be a positive integer.', $name));
        }
        return (int) $value;
    }

    /**
     * @param array<string, string> $options
     * @return array<string, mixed>
     * @throws JsonException
     */
    public static function jsonObject(array $options, string $name, string $default = '{}'): array
    {
        $encoded = $options[$name] ?? $default;
        $object = json_decode($encoded, false, 64, JSON_THROW_ON_ERROR);
        if (!$object instanceof \stdClass) {
            throw new InvalidArgumentException(sprintf('The --%s option must be a JSON object.', $name));
        }

        $value = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new \LogicException('A validated JSON object did not decode to an associative array.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param array<string, mixed> $value */
    public static function render(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function secretFile(string $path): string
    {
        $permissions = $path === '' || !is_file($path) ? false : fileperms($path);
        if (
            !str_starts_with($path, DIRECTORY_SEPARATOR)
            || !is_file($path)
            || is_link($path)
            || !is_readable($path)
            || !is_int($permissions)
            || ($permissions & 0o077) !== 0
        ) {
            throw new InvalidArgumentException(
                'Secret files must be absolute, readable, non-symlinked, and mode 0600.',
            );
        }
        $secret = trim((string) file_get_contents($path));
        if ($secret === '') {
            throw new InvalidArgumentException('The secret file is empty.');
        }
        return $secret;
    }
}
