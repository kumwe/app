<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

use InvalidArgumentException;
use Kumwe\Record\Value\ProtectedRecordValue;
use Kumwe\Secret\Value\EncryptedEnvelope;

/**
 * Host adapter that hands the record-value guard a sealed secret in the one form it admits.
 *
 * `Kumwe\Record\Value\RecordValueGuard` owns admission and canonical spelling of every business-record
 * value, and it deliberately knows nothing about cryptography: a sealed `EncryptedEnvelope` is an
 * unsupported runtime type to it. What it admits instead is `ProtectedRecordValue`, opaque storage the host
 * supplies. This adapter is the one place App makes that substitution — every guard call in App passes its
 * value through `protect()` first — so an envelope inside a record, a revision snapshot, a fingerprint or a
 * diff is admitted and canonicalised exactly as it was before the guard moved. The substitution is
 * `EncryptedEnvelope::toStorage()` unchanged, the same four base64 and identifier strings the App stored and
 * checksummed all along, so no stored checksum, digest or revision byte moves. Nothing is decrypted or
 * verified here; envelope authenticity, key custody and rotation stay with the App's Secret Envelope
 * adapters.
 *
 * @since  2.0.0
 */
final class RecordValueProtection
{
    /**
     * Deepest nesting the guard admits; the walk stops there and leaves the guard to refuse the structure.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAXIMUM_DEPTH = 8;

    /**
     * Replace every sealed envelope in a value with the opaque protected storage the guard admits.
     *
     * Scalars, the domain value objects and `DateTimeImmutable` pass through untouched, so a PHP float or
     * an unsupported object still reaches the guard and is refused there. Arrays are copied and walked to
     * the guard's own depth bound; a deeper structure is returned as it stands and the guard refuses it.
     *
     * @param   mixed  $value  Field value, value map, snapshot or evidence tree about to be admitted or
     *          canonicalised.
     *
     * @return  mixed  The same value with each `EncryptedEnvelope` replaced by a `ProtectedRecordValue`
     *          built from its storage spelling; unchanged when it holds no envelope.
     *
     * @throws  InvalidArgumentException  When an envelope's storage spelling breaches the protected storage
     *          bounds, which a Secret Envelope value cannot do within its own ciphertext limit.
     *
     * @since   2.0.0
     */
    public static function protect(mixed $value): mixed
    {
        return self::substitute($value, 0);
    }

    /**
     * Walk one level of the value, substituting envelopes and descending into arrays within the bound.
     *
     * @param   mixed  $value  Value at this level.
     * @param   int    $depth  Nesting level of $value, 0 at the top.
     *
     * @return  mixed  The value with its envelopes substituted down to the bound.
     *
     * @since   2.0.0
     */
    private static function substitute(mixed $value, int $depth): mixed
    {
        if ($value instanceof EncryptedEnvelope) {
            return new ProtectedRecordValue($value->toStorage());
        }
        if (!is_array($value) || $depth >= self::MAXIMUM_DEPTH) {
            return $value;
        }
        foreach ($value as $key => $item) {
            if ($item instanceof EncryptedEnvelope || is_array($item)) {
                $value[$key] = self::substitute($item, $depth + 1);
            }
        }

        return $value;
    }

    /**
     * Prevent instantiation; the adapter is a policy reached through its static method alone.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
