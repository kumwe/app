<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Domain\Internal\StudioProfile;

use stdClass;

/**
 * The canonical cross-language JSON form Studio contracts compute checksums over.
 *
 * One value must serialize to byte-identical UTF-8 in every conforming runtime: object members
 * sorted by UTF-16 code unit, arrays in semantic order, minimal ECMA-404 string escaping, the
 * deterministic ECMAScript number grammar with negative zero canonicalized to zero, and refusal —
 * never truncation — of over-deep nesting, forbidden member names, and non-JSON values. Objects
 * are `stdClass` and arrays are PHP lists, exactly as `json_decode` without associative mode
 * produces them, because an empty PHP array cannot say whether it was `{}` or `[]`.
 *
 * This is the independent PHP implementation of `canonicalStringify` from `@kumwe/studio-core`,
 * proven by replaying the pinned canonical vector corpus under `tests/Fixtures/Studio/`.
 *
 * @since  2.0.0
 */
final class CanonicalJson
{
    /**
     * Nesting bound a canonical document may not exceed unless the caller widens it.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int DEFAULT_MAXIMUM_DEPTH = 64;

    /**
     * Serialize a decoded JSON value into its canonical UTF-8 form.
     *
     * @param   mixed  $value         Decoded JSON value: null, bool, int, float, string, list array,
     *          or `stdClass`.
     * @param   int    $maximumDepth  Container nesting bound; a deeper value is refused.
     *
     * @return  string  The canonical bytes; PHP strings are byte strings, so this is also the
     *          checksum input.
     *
     * @throws  CanonicalJsonRejected  When the value nests past the bound, carries a forbidden
     *          member name, or is not JSON-representable.
     *
     * @since   2.0.0
     */
    public static function stringify(mixed $value, int $maximumDepth = self::DEFAULT_MAXIMUM_DEPTH): string
    {
        return self::serialize($value, $maximumDepth, 0);
    }

    /**
     * Compare two strings by UTF-16 code unit, the member order every Studio contract sorts by.
     *
     * ASCII compares as plain bytes; anything wider is converted to UTF-16BE, whose big-endian
     * byte comparison is exactly code-unit order — which differs from UTF-8 byte order for
     * supplementary-plane text.
     *
     * @param   string  $left   First member name.
     * @param   string  $right  Second member name.
     *
     * @return  int  Negative, zero or positive as `strcmp` reports.
     *
     * @since   2.0.0
     */
    public static function compareCodeUnits(string $left, string $right): int
    {
        if (!self::isAscii($left) || !self::isAscii($right)) {
            return strcmp(
                (string) mb_convert_encoding($left, 'UTF-16BE', 'UTF-8'),
                (string) mb_convert_encoding($right, 'UTF-16BE', 'UTF-8'),
            );
        }

        return strcmp($left, $right);
    }

    /**
     * Encode one finite number in the deterministic ECMAScript grammar.
     *
     * Integers print plainly. Floats print their shortest round-trip digits in fixed notation for
     * magnitudes in [1e-6, 1e21) and exponent notation outside it, mirroring the ECMAScript
     * Number-to-string algorithm the reference canonicalizer inherits from `JSON.stringify`.
     * Negative zero canonicalizes to `0`.
     *
     * @param   int|float  $value  Finite number to encode.
     *
     * @return  string  The canonical digits.
     *
     * @throws  CanonicalJsonRejected  When the float is not finite.
     *
     * @since   2.0.0
     */
    public static function encodeNumber(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (!is_finite($value)) {
            throw new CanonicalJsonRejected('not-json', 'Canonical JSON cannot represent a non-finite number.');
        }
        if ($value === 0.0) {
            return '0';
        }

        $shortest = json_encode($value);
        if (!is_string($shortest)) {
            throw new CanonicalJsonRejected('not-json', 'A float refused shortest round-trip encoding.');
        }
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $shortest, $parts) !== 1) {
            throw new CanonicalJsonRejected('not-json', 'A float produced an unrecognisable encoding.');
        }
        $sign = $parts[1];
        $all = $parts[2] . ($parts[3] ?? '');
        $point = strlen($parts[2]) + (int) ($parts[4] ?? '0');
        $trimmed = ltrim($all, '0');
        if ($trimmed === '') {
            return '0';
        }
        $point -= strlen($all) - strlen($trimmed);
        $digits = rtrim($trimmed, '0');
        $length = strlen($digits);

        if ($point >= $length && $point <= 21) {
            return $sign . $digits . str_repeat('0', $point - $length);
        }
        if ($point > 0 && $point <= 21) {
            return $sign . substr($digits, 0, $point) . '.' . substr($digits, $point);
        }
        if ($point > -6 && $point <= 0) {
            return $sign . '0.' . str_repeat('0', -$point) . $digits;
        }
        $mantissa = $length === 1 ? $digits : $digits[0] . '.' . substr($digits, 1);
        $exponent = $point - 1;

        return $sign . $mantissa . 'e' . ($exponent >= 0 ? '+' : '') . $exponent;
    }

    /**
     * Escape one string with exactly the short forms and hex escapes ECMA-404 requires.
     *
     * @param   string  $value  Well-formed UTF-8 text.
     *
     * @return  string  The quoted canonical form, with non-ASCII text left as raw UTF-8.
     *
     * @since   2.0.0
     */
    public static function encodeString(string $value): string
    {
        $escaped = '"';
        $length = strlen($value);
        for ($index = 0; $index < $length; $index++) {
            $byte = $value[$index];
            $code = ord($byte);
            $escaped .= match (true) {
                $byte === '"' => '\\"',
                $byte === '\\' => '\\\\',
                $code === 0x08 => '\\b',
                $code === 0x09 => '\\t',
                $code === 0x0A => '\\n',
                $code === 0x0C => '\\f',
                $code === 0x0D => '\\r',
                $code <= 0x1F => sprintf('\\u%04x', $code),
                default => $byte,
            };
        }

        return $escaped . '"';
    }

    /**
     * Serialize one node, refusing anything the canonical form cannot carry.
     *
     * @param   mixed  $value         Node to serialize.
     * @param   int    $maximumDepth  Container nesting bound.
     * @param   int    $depth         Containers already entered.
     *
     * @return  string  Canonical bytes of this node.
     *
     * @throws  CanonicalJsonRejected  When the node is refused.
     *
     * @since   2.0.0
     */
    private static function serialize(mixed $value, int $maximumDepth, int $depth): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return self::encodeNumber($value);
        }
        if (is_string($value)) {
            return self::encodeString($value);
        }
        if (!is_array($value) && !$value instanceof stdClass) {
            throw new CanonicalJsonRejected('not-json', 'Canonical JSON only serializes decoded JSON values.');
        }
        if ($depth >= $maximumDepth) {
            throw new CanonicalJsonRejected(
                'depth-exceeded',
                sprintf('Canonical serialization exceeds the depth limit of %d.', $maximumDepth),
            );
        }

        if (is_array($value)) {
            if (!array_is_list($value)) {
                throw new CanonicalJsonRejected('not-json', 'Canonical JSON arrays must be lists.');
            }
            $items = [];
            foreach ($value as $item) {
                $items[] = self::serialize($item, $maximumDepth, $depth + 1);
            }

            return '[' . implode(',', $items) . ']';
        }

        $members = array_map(strval(...), array_keys(get_object_vars($value)));
        usort($members, self::compareCodeUnits(...));
        $parts = [];
        foreach ($members as $member) {
            if ($member === '__proto__' || $member === 'prototype' || $member === 'constructor') {
                throw new CanonicalJsonRejected(
                    'forbidden-member',
                    sprintf('Canonical JSON forbids the object member name %s.', $member),
                );
            }
            $parts[] = self::encodeString($member) . ':'
                . self::serialize($value->{$member}, $maximumDepth, $depth + 1);
        }

        return '{' . implode(',', $parts) . '}';
    }

    /**
     * Say whether a string is pure ASCII and safe for byte-order comparison.
     *
     * @param   string  $value  Candidate member name.
     *
     * @return  bool  True when no byte is above 0x7F.
     *
     * @since   2.0.0
     */
    private static function isAscii(string $value): bool
    {
        return preg_match('/[\x80-\xFF]/', $value) !== 1;
    }
}
