<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use InvalidArgumentException;
use JsonException;

/**
 * Order-independent JSON encoder the automation digests are computed over.
 *
 * Automation compares payloads it never keeps in full: an idempotency record stores only the digest of
 * the request that opened it and rejects a replay whose digest differs, a schedule occurrence becomes a
 * key by digesting its identifier and instant, and a change plan fingerprints the command and payload
 * it previews. Those comparisons span processes and outlive the request that made them, so the encoding
 * must not depend on the order keys happen to sit in an array — string-keyed arrays are sorted before
 * encoding, while lists keep their positions because order means something there. The value space is
 * deliberately narrow: null, bool, int, float, string and arrays of those, and nothing else, so an
 * object, a resource or a non-finite float is refused outright instead of being digested into something
 * that cannot be reproduced. `JobEnvelope` leans on that, calling `encode()` for the rejection alone to
 * prove a payload is representable before the job is stored.
 *
 * @since  2.0.0
 */
final class CanonicalJson
{
    /**
     * Encoder flags that keep the output byte-stable and safe to hash.
     *
     * Zero fractions are preserved so `1.0` does not collapse into `1` and change the digest, slashes
     * and unicode are left unescaped so the bytes do not depend on the encoder's escaping mood, and
     * errors are raised rather than returned as `false`.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int ENCODE_FLAGS = JSON_PRESERVE_ZERO_FRACTION
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    /**
     * Encode a value into the canonical form automation digests are taken over.
     *
     * @param   mixed  $value  Value to encode; arrays are normalised recursively first.
     *
     * @return  string  Canonical JSON, with the keys of every string-keyed array sorted by byte value.
     *
     * @throws  InvalidArgumentException  When the value holds a type canonical JSON does not accept, a
     *          non-finite float, or a string that is not valid UTF-8.
     *
     * @since   2.0.0
     */
    public static function encode(mixed $value): string
    {
        try {
            return json_encode(self::normalize($value), self::ENCODE_FLAGS);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The value cannot be represented as canonical JSON.', 0, $exception);
        }
    }

    /**
     * Reduce a value to the fixed-width digest automation stores and compares in place of the value.
     *
     * @param   mixed  $value  Value to fingerprint; encoded canonically before it is hashed.
     *
     * @return  string  Lowercase hexadecimal SHA-256 of the canonical encoding, 64 characters wide.
     *
     * @throws  InvalidArgumentException  When the value cannot be represented as canonical JSON.
     *
     * @since   2.0.0
     */
    public static function digest(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    /**
     * Put a value into the shape whose encoding no longer depends on key insertion order.
     *
     * Lists are mapped element by element because their order carries meaning; every other array is
     * sorted with `SORT_STRING` and its members normalised in turn. This is also the point where the
     * accepted value space is enforced, so `encode()` never has to reason about what `json_encode()`
     * would make of an object, a resource, `NAN` or `INF`.
     *
     * @param   mixed  $value  Value to normalise.
     *
     * @return  mixed  Scalars and null unchanged, arrays rebuilt with their members normalised and
     *          their string keys ordered.
     *
     * @throws  InvalidArgumentException  When the value is a non-finite float, or is of any type other
     *          than null, bool, int, float, string or array.
     *
     * @since   2.0.0
     */
    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::normalize(...), $value);
            }

            ksort($value, SORT_STRING);

            foreach ($value as $key => $item) {
                $value[$key] = self::normalize($item);
            }

            return $value;
        }

        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('Canonical JSON does not support non-finite numbers.');
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException(
            sprintf('Canonical JSON does not support values of type "%s".', get_debug_type($value)),
        );
    }
}
