<?php

declare(strict_types=1);

namespace Kumwe\CMS\Shared\Infrastructure\Configuration;

use InvalidArgumentException;

final readonly class Environment
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(private array $values)
    {
    }

    /**
     * The only first-party boundary permitted to read the process environment.
     */
    public static function fromGlobals(): self
    {
        $values = [];

        foreach (array_keys($_ENV + $_SERVER) as $key) {
            if (!is_string($key)) {
                continue;
            }

            $value = getenv($key);

            if (is_string($value)) {
                $values[$key] = $value;
            }
        }

        return new self($values);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values) && $this->values[$name] !== '';
    }

    public function string(string $name, ?string $default = null): string
    {
        $value = $this->values[$name] ?? $default;

        if ($value === null || $value === '') {
            throw new InvalidArgumentException(sprintf('Required environment variable "%s" is not configured.', $name));
        }

        return $value;
    }

    public function optionalString(string $name, ?string $default = null): ?string
    {
        $value = $this->values[$name] ?? $default;

        return $value === '' ? $default : $value;
    }

    public function boolean(string $name, bool $default = false): bool
    {
        $value = $this->values[$name] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new InvalidArgumentException(
                sprintf('Environment variable "%s" must contain a boolean value.', $name),
            ),
        };
    }

    public function positiveInteger(string $name, int $default): int
    {
        $value = $this->values[$name] ?? (string) $default;

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException(
                sprintf('Environment variable "%s" must contain a positive integer.', $name),
            );
        }

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    public function commaSeparatedList(string $name): array
    {
        $value = $this->values[$name] ?? '';

        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $item): string => trim($item), explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
