<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\App\Shared\Domain\CanonicalJson;
use Kumwe\CanonicalJson\CanonicalEncoder;

/**
 * Deterministic canonical encoder for unit tests that run without the native runtime.
 *
 * The package values, manifests and adapters this train adopts take the host encoder through their
 * constructors; the production binding is the native Computation encoder, which the unit lane cannot
 * load. This double routes both operations through the App's shared PHP canonical JSON, so every byte
 * a test compares is reproducible and no test invents a second encoding of its own.
 *
 * @since  2.0.0
 */
final readonly class DeterministicCanonicalEncoder implements CanonicalEncoder
{
    /**
     * Encode a value as byte-reproducible canonical JSON.
     *
     * @param   mixed  $value  Value to encode; string-keyed arrays are ordered by key first.
     *
     * @return  string  Canonical JSON bytes.
     *
     * @since   2.0.0
     */
    public function encode(mixed $value): string
    {
        return CanonicalJson::encode($value);
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
        return CanonicalJson::digest($value);
    }
}
