<?php

declare(strict_types=1);

namespace Kumwe\CMS\Shared\Infrastructure\Configuration;

use InvalidArgumentException;

final readonly class Environment
{
    private const PROCESS_KEYS = [
        'APP_ENV',
        'APP_DEBUG',
        'APP_BASE_URL',
        'APP_TRUSTED_HOSTS',
        'APP_TRUSTED_PROXIES',
        'APP_MAX_BODY_BYTES',
        'APP_ADMIN_SESSION_SECONDS',
        'APP_SECRET',
        'EXTENSION_RUNTIME_SIGNING_KEY_ID',
        'EXTENSION_RUNTIME_SIGNING_KEY',
        'EXTENSION_RUNTIME_PREVIOUS_KEYS',
        'EXTENSION_RUNTIME_PREVIOUS_KEYS_FILE',
        'EXTENSIONS_ALLOW_UNSIGNED_LOCAL',
        'KUMWE_RELEASE',
        'KUMWE_DEPLOYMENT_ID',
        'KUMWE_REPLICA_ID',
        'KUMWE_PROCESS_ID',
        'KUMWE_INSTANCE_ID',
        'DB_DRIVER',
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'DB_TABLE_PREFIX',
        'DB_SERVER_VERSION',
        'DB_SSLMODE',
        'REDIS_HOST',
        'REDIS_PORT',
        'REDIS_PASSWORD',
        'REDIS_DATABASE',
        'REDIS_NAMESPACE',
    ];

    /**
     * @param array<string, string> $values
     */
    public function __construct(private array $values)
    {
    }

    /**
     * The only first-party boundary permitted to read the process environment.
     */
    public static function fromGlobals(?string $dotenvFile = null): self
    {
        $dotenvFile ??= dirname(__DIR__, 4) . '/.env';
        $values = self::readDotenv($dotenvFile);

        foreach (self::PROCESS_KEYS as $key) {
            $value = getenv($key);

            if (is_string($value)) {
                $values[$key] = $value;
            }
        }

        return new self($values);
    }

    /**
     * Read only Kumwe's allow-listed settings. Process environment values are
     * applied afterwards and therefore always take precedence over this file.
     *
     * @return array<string, string>
     */
    private static function readDotenv(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if (!is_array($lines)) {
            throw new InvalidArgumentException(sprintf('Environment file "%s" could not be read.', $path));
        }

        $allowed = array_fill_keys(self::PROCESS_KEYS, true);
        $values = [];

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $separator = strpos($line, '=');

            if ($separator === false) {
                throw new InvalidArgumentException(sprintf(
                    'Environment file "%s" contains an invalid assignment on line %d.',
                    $path,
                    $lineNumber + 1,
                ));
            }

            $key = trim(substr($line, 0, $separator));

            if (!isset($allowed[$key])) {
                continue;
            }

            $values[$key] = self::parseDotenvValue(
                trim(substr($line, $separator + 1)),
                $path,
                $lineNumber + 1,
            );
        }

        return $values;
    }

    private static function parseDotenvValue(string $value, string $path, int $lineNumber): string
    {
        if ($value === '') {
            return '';
        }

        $quote = $value[0];

        if ($quote !== '"' && $quote !== "'") {
            $comment = strpos($value, ' #');

            return trim($comment === false ? $value : substr($value, 0, $comment));
        }

        if (strlen($value) < 2 || !str_ends_with($value, $quote)) {
            throw new InvalidArgumentException(sprintf(
                'Environment file "%s" contains an unterminated quoted value on line %d.',
                $path,
                $lineNumber,
            ));
        }

        $value = substr($value, 1, -1);
        if ($quote !== '"') {
            return $value;
        }

        $decoded = '';
        $length = strlen($value);
        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];
            if ($character !== '\\' || $index + 1 >= $length) {
                $decoded .= $character;
                continue;
            }
            $escaped = $value[++$index];
            $decoded .= match ($escaped) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                '"' => '"',
                '\\' => '\\',
                default => '\\' . $escaped,
            };
        }

        return $decoded;
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

    public function nonNegativeInteger(string $name, int $default, int $maximum): int
    {
        $value = $this->values[$name] ?? (string) $default;
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0 || (int) $value > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'Environment variable "%s" must contain an integer between 0 and %d.',
                $name,
                $maximum,
            ));
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
