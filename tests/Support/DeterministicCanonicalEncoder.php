<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use InvalidArgumentException;
use JsonException;
use Kumwe\CanonicalJson\CanonicalEncoder;

/**
 * Deterministic canonical encoder for unit tests that run without the native runtime.
 *
 * The package values, manifests and adapters the App composes take the host encoder through their
 * constructors; the production binding is the native Computation encoder, which the engine-free part of the
 * unit lane cannot load. This double spells a value as byte-reproducible JSON — string-keyed arrays ordered
 * by key, lists kept in position, zero fractions preserved, slashes and unicode unescaped — so every byte a
 * test compares is reproducible and no test invents a second encoding of its own. It is a test double: the
 * generic-v1 profile is owned by `kumwe/canonical-json`, executed by the native encoder, and proven for the
 * App's composed bindings by `CanonicalEncoderConformanceTest`.
 *
 * @since  2.0.0
 */
final readonly class DeterministicCanonicalEncoder implements CanonicalEncoder
{
    /**
     * Encoder flags that keep the output byte-stable and safe to hash.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int ENCODE_FLAGS = JSON_PRESERVE_ZERO_FRACTION
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    /**
     * Encode a value as byte-reproducible canonical JSON.
     *
     * @param   mixed  $value  Value to encode; string-keyed arrays are ordered by key first.
     *
     * @return  string  Canonical JSON bytes.
     *
     * @throws  InvalidArgumentException  When the value holds a type the double does not spell, a
     *          non-finite float, or a string that is not valid UTF-8.
     *
     * @since   2.0.0
     */
    public function encode(mixed $value): string
    {
        try {
            return json_encode(self::normalize($value), self::ENCODE_FLAGS);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The value cannot be represented as canonical JSON.', 0, $exception);
        }
    }

    /**
     * Digest exactly the bytes `encode()` produces.
     *
     * @param   mixed  $value  Value to fingerprint.
     *
     * @return  string  Lowercase hexadecimal SHA-256 of the canonical bytes.
     *
     * @since   2.0.0
     */
    public function digest(mixed $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    /**
     * Put a value into the shape whose encoding no longer depends on key insertion order.
     *
     * @param   mixed  $value  Value to normalise.
     *
     * @return  mixed  Scalars and null unchanged, arrays rebuilt with their members normalised and their
     *          string keys ordered.
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
