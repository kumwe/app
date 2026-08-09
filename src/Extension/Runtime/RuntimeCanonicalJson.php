<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use InvalidArgumentException;
use JsonException;

/**
 * Byte-reproducible JSON encoder every runtime digest and signature payload is computed over.
 *
 * Runtime trust rests on comparing SHA-256 digests and HMACs that were produced on one host and
 * verified on another, possibly by a different PHP build, so the encoding cannot depend on insertion
 * order or on how PHP happens to key an array. String-keyed arrays are sorted and emitted as JSON
 * objects even when their keys look sequential, lists keep their positional order, and slashes and
 * unicode are left unescaped. Signing and verification must both route through this class; encoding
 * the same state any other way produces different bytes and reads as tampering.
 *
 * @since  2.0.0
 */
final class RuntimeCanonicalJson
{
    /**
     * Encode a value into the canonical form runtime checksums and signatures are taken over.
     *
     * @param   mixed  $value  Runtime state to encode; arrays are canonicalized recursively first.
     *
     * @return  string  Canonical JSON with object keys sorted by byte value and slashes and unicode
     *          left unescaped.
     *
     * @throws  InvalidArgumentException  When the value holds something JSON cannot represent, such as
     *          a resource, malformed UTF-8, or a non-finite float.
     *
     * @since   2.0.0
     */
    public static function encode(mixed $value): string
    {
        try {
            return json_encode(
                self::canonicalize($value),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Runtime state cannot be represented as canonical JSON.', 0, $exception);
        }
    }

    /**
     * Normalise a value so its encoding no longer depends on key insertion order.
     *
     * Lists are mapped element by element because their order carries meaning. Every other array is
     * sorted with `SORT_STRING` and cast to an object, which also stops an array whose keys happen to
     * be `0..n` after a deletion from being encoded as a JSON array in one process and an object in
     * another.
     *
     * @param   mixed  $value  Value to normalise.
     *
     * @return  mixed  Scalars and objects unchanged, lists as arrays of normalised elements, and every
     *          other array as a key-sorted `stdClass`.
     *
     * @since   2.0.0
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return (object) $value;
    }
}
