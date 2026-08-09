<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Shared field readers and identifier rules for every persisted business-schema document.
 *
 * Blueprints, plans, operations, and installations are all rebuilt from untrusted arrays — database rows,
 * API payloads, fixtures — so each `fromArray()` in this namespace reads its fields through these helpers
 * rather than testing types inline. That keeps one rejection vocabulary: a bad document always raises
 * `InvalidBusinessSchema` naming the offending property, and every identifier that will end up inside a
 * physical name or an SQL statement is checked against the same bounded grammar before it travels further.
 *
 * The class is a pure helper namespace: it carries no state and its private constructor blocks instances.
 *
 * @since  2.0.0
 */
final class SchemaDocument
{
    /**
     * Reject a document that carries any property outside the declared set.
     *
     * Documents are closed rather than tolerant, so an unrecognised key is treated as a version or
     * corruption problem instead of being silently dropped on the next round trip.
     *
     * @param   array<string, mixed>  $document  Decoded document to inspect.
     * @param   list<string>          $allowed   Property names this document shape declares.
     * @param   string                $subject   Sentence-leading name of the document, used in the failure message.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the document holds a property outside the allowed set.
     *
     * @since   2.0.0
     */
    public static function assertOnly(array $document, array $allowed, string $subject): void
    {
        if (array_diff(array_keys($document), $allowed) !== []) {
            throw new InvalidBusinessSchema($subject . ' contains an unknown property.');
        }
    }

    /**
     * Read a required, non-blank string property.
     *
     * @param   array<string, mixed>  $document  Decoded document to read from.
     * @param   string                $key       Property name to read.
     *
     * @return  string  The value with surrounding whitespace removed.
     *
     * @throws  InvalidBusinessSchema  When the property is absent, not a string, or blank.
     *
     * @since   2.0.0
     */
    public static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidBusinessSchema('Schema property ' . $key . ' must be a non-empty string.');
        }

        return trim($value);
    }

    /**
     * Read an optional string property that may be absent or explicitly null.
     *
     * @param   array<string, mixed>  $document  Decoded document to read from.
     * @param   string                $key       Property name to read.
     *
     * @return  string|null  The trimmed value, or null when the property is absent or null.
     *
     * @throws  InvalidBusinessSchema  When the property is present and non-null but is not a non-blank string.
     *
     * @since   2.0.0
     */
    public static function nullableString(array $document, string $key): ?string
    {
        $value = $document[$key] ?? null;
        if ($value !== null && (!is_string($value) || trim($value) === '')) {
            throw new InvalidBusinessSchema('Schema property ' . $key . ' must be null or a non-empty string.');
        }

        return is_string($value) ? trim($value) : null;
    }

    /**
     * Read a required integer property, refusing numeric strings and floats.
     *
     * @param   array<string, mixed>  $document  Decoded document to read from.
     * @param   string                $key       Property name to read.
     *
     * @return  int  The exact stored integer.
     *
     * @throws  InvalidBusinessSchema  When the property is absent or is not an integer.
     *
     * @since   2.0.0
     */
    public static function integer(array $document, string $key): int
    {
        $value = $document[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidBusinessSchema('Schema property ' . $key . ' must be an integer.');
        }

        return $value;
    }

    /**
     * Read an optional integer property that may be absent or explicitly null.
     *
     * @param   array<string, mixed>  $document  Decoded document to read from.
     * @param   string                $key       Property name to read.
     *
     * @return  int|null  The stored integer, or null when the property is absent or null.
     *
     * @throws  InvalidBusinessSchema  When the property is present and non-null but is not an integer.
     *
     * @since   2.0.0
     */
    public static function nullableInteger(array $document, string $key): ?int
    {
        $value = $document[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new InvalidBusinessSchema('Schema property ' . $key . ' must be null or an integer.');
        }

        return $value;
    }

    /**
     * Read a boolean property, falling back to a caller-chosen default when it is absent.
     *
     * An explicit null is not treated as absence: it fails, because a flag that was persisted as null
     * means the document is malformed rather than defaulted.
     *
     * @param   array<string, mixed>  $document  Decoded document to read from.
     * @param   string                $key       Property name to read.
     * @param   bool                  $default   Value to use when the property is absent.
     *
     * @return  bool  The stored flag, or the default.
     *
     * @throws  InvalidBusinessSchema  When the property is present but is not a boolean.
     *
     * @since   2.0.0
     */
    public static function boolean(array $document, string $key, bool $default = false): bool
    {
        $value = $document[$key] ?? $default;
        if (!is_bool($value)) {
            throw new InvalidBusinessSchema('Schema property ' . $key . ' must be boolean.');
        }

        return $value;
    }

    /**
     * Read a nested object property as a string-keyed map.
     *
     * @param   array<string, mixed>  $document  Decoded document to read from.
     * @param   string                $key       Property name to read.
     * @param   bool                  $nullable  Whether an absent or null property is acceptable.
     *
     * @return  array<string, mixed>|null  The nested object, or null only when it was absent and $nullable
     *          was requested.
     *
     * @throws  InvalidBusinessSchema  When the property is not an array, is a non-empty list, or uses a
     *          non-string key; and when it is missing while $nullable is false.
     *
     * @since   2.0.0
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
     * Read a property holding a list of nested objects, as every collection field does.
     *
     * @param   array<string, mixed>  $document  Decoded document to read from.
     * @param   string                $key       Property name to read.
     *
     * @return  list<array<string, mixed>>  The entries in stored order; an empty list when the property
     *          holds an empty list.
     *
     * @throws  InvalidBusinessSchema  When the property is absent, is not a list, or holds an entry that
     *          is not a string-keyed object.
     *
     * @since   2.0.0
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
     * Read a property holding a list of non-blank strings, such as a key's column names.
     *
     * @param   array<string, mixed>  $document  Decoded document to read from.
     * @param   string                $key       Property name to read.
     *
     * @return  list<string>  The entries in stored order, each trimmed; order is significant for the
     *          column lists this reads.
     *
     * @throws  InvalidBusinessSchema  When the property is absent, is not a list, or holds a blank or
     *          non-string entry.
     *
     * @since   2.0.0
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

    /**
     * Require a canonical UUID, as every definition and plan identity must be.
     *
     * @param   string  $value    Candidate identifier.
     * @param   string  $subject  Sentence-leading name of the field, used in the failure message.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the value is not a canonical UUID.
     *
     * @since   2.0.0
     */
    public static function assertUuid(string $value, string $subject): void
    {
        if (!Uuid::isValid($value)) {
            throw new InvalidBusinessSchema($subject . ' must be a canonical UUID.');
        }
    }

    /**
     * Require a lowercase hexadecimal SHA-256 digest, optionally allowing its absence.
     *
     * Checksums are the only thing binding an approval, an installation, and a live schema together, so a
     * digest in an unexpected case or length is rejected rather than normalized.
     *
     * @param   string|null  $value     Candidate digest.
     * @param   string       $subject   Sentence-leading name of the field, used in the failure message.
     * @param   bool         $nullable  Whether a null digest is a legitimate "not recorded" value.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the value is not 64 lowercase hexadecimal characters, or is
     *          null while $nullable is false.
     *
     * @since   2.0.0
     */
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

    /**
     * Require a metadata identifier: lowercase alphanumeric groups joined by `.`, `_`, `:`, or `-`.
     *
     * These are the logical handles a plan refers to — table, column, index, and foreign-key names inside
     * the blueprint — so the grammar stays narrow enough to be safe in messages, keys, and comparisons.
     *
     * @param   string  $value    Candidate identifier.
     * @param   string  $subject  Sentence-leading name of the field, used in the failure message.
     * @param   int     $maximum  Longest accepted length in bytes.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the value breaks the grammar or exceeds the length budget.
     *
     * @since   2.0.0
     */
    public static function assertIdentifier(string $value, string $subject, int $maximum = 191): void
    {
        if (
            strlen($value) > $maximum
            || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+)*$/D', $value) !== 1
        ) {
            throw new InvalidBusinessSchema($subject . ' is not a validated metadata identifier.');
        }
    }

    /**
     * Require free text that is present, length bounded, and free of control characters.
     *
     * Use this for operator-facing values such as an owner or approver identity, which are stored and
     * echoed back but are not identifiers.
     *
     * @param   string  $value    Candidate text.
     * @param   string  $subject  Sentence-leading name of the field, used in the failure message.
     * @param   int     $maximum  Longest accepted length in bytes.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the value is empty, too long, or carries control characters.
     *
     * @since   2.0.0
     */
    public static function assertBoundedText(string $value, string $subject, int $maximum = 191): void
    {
        if ($value === '' || strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidBusinessSchema($subject . ' is empty, too long, or contains control characters.');
        }
    }

    /**
     * Require an already-decoded array to be a string-keyed object rather than a list.
     *
     * An empty array passes, because JSON cannot distinguish `[]` from `{}` once decoded.
     *
     * @param   array<array-key, mixed>  $value    Decoded value to classify.
     * @param   string                   $subject  Sentence-leading name of the field, used in the failure message.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the value is a non-empty list or holds a non-string key.
     *
     * @since   2.0.0
     */
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

    /**
     * Require a name that is legal as a physical table, column, index, or constraint identifier.
     *
     * The 63-byte ceiling and the lowercase letter, digit, underscore alphabet are PostgreSQL's limit
     * applied everywhere, so one compiled blueprint installs unchanged on every supported engine.
     *
     * @param   string  $value    Candidate physical name.
     * @param   string  $subject  Sentence-leading name of the field, used in the failure message.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the name is over 63 bytes or outside the portable alphabet.
     *
     * @since   2.0.0
     */
    public static function assertPhysicalIdentifier(string $value, string $subject): void
    {
        if (strlen($value) > 63 || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $value) !== 1) {
            throw new InvalidBusinessSchema($subject . ' is not a portable physical identifier.');
        }
    }

    /**
     * Parse a stored timestamp into UTC.
     *
     * @param   string  $value    Timestamp text as persisted.
     * @param   string  $subject  Sentence-leading name of the field, used in the failure message.
     *
     * @return  DateTimeImmutable  The instant converted to UTC, whatever offset it was written with.
     *
     * @throws  InvalidBusinessSchema  When the text is not a readable date and time.
     *
     * @since   2.0.0
     */
    public static function date(string $value, string $subject): DateTimeImmutable
    {
        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            throw new InvalidBusinessSchema($subject . ' is not a valid timestamp.', 0, $exception);
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Render an instant in the one timestamp format every schema document persists.
     *
     * Because the format is fixed-width UTC with microseconds, stored timestamps sort lexically in the
     * same order they occurred, and a document round trip is byte stable.
     *
     * @param   DateTimeInterface  $value  Instant to render, in any timezone.
     *
     * @return  string  UTC timestamp as `Y-m-d\TH:i:s.u\Z`.
     *
     * @since   2.0.0
     */
    public static function formatDate(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * Block instantiation; every member of this helper is static.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
