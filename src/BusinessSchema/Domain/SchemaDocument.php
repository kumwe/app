<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Ramsey\Uuid\Uuid;
use Throwable;

final class SchemaDocument
{
    /**
     * @param array<string, mixed> $document
     * @param list<string> $allowed
     */
    public static function assertOnly(array $document, array $allowed, string $subject): void
    {
        if (array_diff(array_keys($document), $allowed) !== []) {
            throw new InvalidBusinessSchema($subject . ' contains an unknown property.');
        }
    }

    /** @param array<string, mixed> $document */
    public static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidBusinessSchema('Schema property ' . $key . ' must be a non-empty string.');
        }

        return trim($value);
    }

    /** @param array<string, mixed> $document */
    public static function nullableString(array $document, string $key): ?string
    {
        $value = $document[$key] ?? null;
        if ($value !== null && (!is_string($value) || trim($value) === '')) {
            throw new InvalidBusinessSchema('Schema property ' . $key . ' must be null or a non-empty string.');
        }

        return is_string($value) ? trim($value) : null;
    }

    /** @param array<string, mixed> $document */
    public static function integer(array $document, string $key): int
    {
        $value = $document[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidBusinessSchema('Schema property ' . $key . ' must be an integer.');
        }

        return $value;
    }

    /** @param array<string, mixed> $document */
    public static function nullableInteger(array $document, string $key): ?int
    {
        $value = $document[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new InvalidBusinessSchema('Schema property ' . $key . ' must be null or an integer.');
        }

        return $value;
    }

    /** @param array<string, mixed> $document */
    public static function boolean(array $document, string $key, bool $default = false): bool
    {
        $value = $document[$key] ?? $default;
        if (!is_bool($value)) {
            throw new InvalidBusinessSchema('Schema property ' . $key . ' must be boolean.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null
     */
    public static function object(array $document, string $key, bool $nullable = false): ?array
    {
        $value = $document[$key] ?? null;
        if ($value === null && $nullable) {
            return null;
        }
        if (!is_array($value)) {
            throw new InvalidBusinessSchema('Schema property ' . $key . ' must be an object.');
        }
        self::assertObjectValue($value, 'Schema property ' . $key);
        /** @var array<string, mixed> $value */

        return $value;
    }

    /**
     * @param array<string, mixed> $document
     * @return list<array<string, mixed>>
     */
    public static function objects(array $document, string $key): array
    {
        $value = $document[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidBusinessSchema('Schema property ' . $key . ' must be a list.');
        }
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new InvalidBusinessSchema('Schema property ' . $key . ' must contain only objects.');
            }
            foreach (array_keys($item) as $itemKey) {
                if (!is_string($itemKey)) {
                    throw new InvalidBusinessSchema(
                        'Schema property ' . $key . ' object entries must use string keys.',
                    );
                }
            }
        }
        /** @var list<array<string, mixed>> $value */

        return $value;
    }

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    public static function strings(array $document, string $key): array
    {
        $value = $document[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidBusinessSchema('Schema property ' . $key . ' must be a list.');
        }
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new InvalidBusinessSchema('Schema property ' . $key . ' must contain non-empty strings.');
            }
        }

        return array_map(trim(...), $value);
    }

    public static function assertUuid(string $value, string $subject): void
    {
        if (!Uuid::isValid($value)) {
            throw new InvalidBusinessSchema($subject . ' must be a canonical UUID.');
        }
    }

    public static function assertChecksum(?string $value, string $subject, bool $nullable = false): void
    {
        if ($value === null) {
            if ($nullable) {
                return;
            }
            throw new InvalidBusinessSchema($subject . ' must be a lowercase SHA-256 checksum.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidBusinessSchema($subject . ' must be a lowercase SHA-256 checksum.');
        }
    }

    public static function assertIdentifier(string $value, string $subject, int $maximum = 191): void
    {
        if (
            strlen($value) > $maximum
            || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+)*$/D', $value) !== 1
        ) {
            throw new InvalidBusinessSchema($subject . ' is not a validated metadata identifier.');
        }
    }

    public static function assertBoundedText(string $value, string $subject, int $maximum = 191): void
    {
        if ($value === '' || strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidBusinessSchema($subject . ' is empty, too long, or contains control characters.');
        }
    }

    /** @param array<array-key, mixed> $value */
    public static function assertObjectValue(array $value, string $subject): void
    {
        if ($value !== [] && array_is_list($value)) {
            throw new InvalidBusinessSchema($subject . ' must be an object, not a list.');
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new InvalidBusinessSchema($subject . ' must use string object keys.');
            }
        }
    }

    public static function assertPhysicalIdentifier(string $value, string $subject): void
    {
        if (strlen($value) > 63 || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $value) !== 1) {
            throw new InvalidBusinessSchema($subject . ' is not a portable physical identifier.');
        }
    }

    public static function date(string $value, string $subject): DateTimeImmutable
    {
        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            throw new InvalidBusinessSchema($subject . ' is not a valid timestamp.', 0, $exception);
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    public static function formatDate(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    private function __construct()
    {
    }
}
