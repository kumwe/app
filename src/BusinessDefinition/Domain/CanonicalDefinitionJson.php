<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

use JsonException;

/**
 * Byte-stable JSON encoder that every business-definition checksum and document comparison runs through.
 *
 * A published definition version is immutable and identified by a SHA-256 over these bytes, and package
 * synchronization decides whether a declaration changed by comparing a stored checksum against a freshly
 * encoded one. Those comparisons cross processes and outlive the request that made them, so the encoding
 * cannot depend on the order keys happen to sit in: string-keyed arrays are sorted, while lists keep
 * their positions because order carries meaning there. The accepted value space is deliberately narrow —
 * floats, resources, and objects are refused outright rather than digested into something that will not
 * reproduce — and nesting depth and collection size are bounded so a definition cannot carry unbounded
 * structure into the checksum. `FieldDefinition` calls `encode()` purely for that rejection, proving a
 * default, a configuration map, or a validator is representable before it is allowed into a definition,
 * and `Expression` measures the encoded length against its own byte budget.
 *
 * @since  2.0.0
 */
final class CanonicalDefinitionJson
{
    /**
     * Encode a value into the canonical bytes definitions are stored, compared, and hashed as.
     *
     * The value space is checked in full before anything is encoded, so an unsupported value is reported
     * as a definition error naming the offending shape rather than as a JSON failure after the fact.
     *
     * @param   mixed  $value  Value to encode; string-keyed arrays are sorted recursively first.
     *
     * @return  string  Canonical JSON with slashes and unicode left unescaped and zero fractions preserved.
     *
     * @throws  InvalidBusinessDefinition  When the value nests deeper than 32 levels, holds a collection of
     *          more than 512 entries, contains a float, resource, or object, or cannot be JSON encoded — a
     *          malformed UTF-8 string being the remaining case.
     *
     * @since   2.0.0
     */
    public static function encode(mixed $value): string
    {
        self::assertSafe($value);

        try {
            return json_encode(
                self::normalize($value),
                JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidBusinessDefinition('The business definition is not JSON serializable.', 0, $exception);
        }
    }

    /**
     * Reduce a value to the digest a published version is identified and later re-verified by.
     *
     * @param   mixed  $value  Value to fingerprint; canonically encoded before it is hashed.
     *
     * @return  string  Lowercase hexadecimal SHA-256 of the canonical encoding, 64 characters wide.
     *
     * @throws  InvalidBusinessDefinition  When the value cannot be canonically encoded.
     *
     * @since   2.0.0
     */
    public static function checksum(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    /**
     * Put a value into the shape whose encoding no longer depends on key insertion order.
     *
     * Lists are mapped element by element because their position is part of the meaning; every other
     * array is sorted with `SORT_STRING` and its members normalized in turn. Runs only after
     * `assertSafe()` has cleared the value, so it never has to reason about unsupported types.
     *
     * @param   mixed  $value  Value to normalize.
     *
     * @return  mixed  Scalars and null unchanged, arrays rebuilt with their string keys ordered.
     *
     * @since   2.0.0
     */
    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }
        return $value;
    }

    /**
     * Refuse a value the canonical form cannot reproduce or cannot bound, before any encoding happens.
     *
     * Floats are rejected alongside resources and objects because a definition expresses exact numbers
     * as base-10 strings, and a float's JSON form depends on the runtime's serialization precision. The
     * depth and size ceilings are walked as part of the same descent, so an over-nested or over-wide
     * import is refused before any of it is encoded.
     *
     * @param   mixed  $value  Value to inspect at this level of the descent.
     * @param   int    $depth  Nesting level reached so far; the walk refuses to go past 32.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When nesting passes 32 levels, an array holds more than 512
     *          entries, or a float, resource, or object appears anywhere in the value.
     *
     * @since   2.0.0
     */
    private static function assertSafe(mixed $value, int $depth = 0): void
    {
        if ($depth > 32) {
            throw new InvalidBusinessDefinition('A business definition exceeds the maximum nesting depth.');
        }
        if (is_float($value) || is_resource($value) || is_object($value)) {
            throw new InvalidBusinessDefinition('Business definitions cannot contain floats, resources, or objects.');
        }
        if (!is_array($value)) {
            return;
        }
        if (count($value) > 512) {
            throw new InvalidBusinessDefinition('A business-definition collection exceeds 512 entries.');
        }
        foreach ($value as $item) {
            self::assertSafe($item, $depth + 1);
        }
    }

    /**
     * Block instantiation; every member of this encoder is static.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
