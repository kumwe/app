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
 * The record and revision aggregates are `Kumwe\Record\Model\BusinessRecord` and
 * `Kumwe\Record\Model\BusinessRecordRevision`, whose constructors hand every value to the guard themselves,
 * so App applies this substitution to the value map immediately before it constructs or advances one of
 * them: a stored record therefore carries each sealed secret as the `ProtectedRecordValue` the guard admits,
 * and `RecordValueCodec` recognises that form again when it seals or stores the field.
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
     * the guard's own depth bound; a deeper structure is returned as it stands and the guard refuses it. An
     * array comes back as an array with the same keys in the same order, which is what lets a whole value
     * map be protected in place of the map a record or revision constructor is handed.
     *
     * @param   mixed  $value  Field value, value map, snapshot or evidence tree about to be admitted or
     *          canonicalised.
     *
     * @return  ($value is array ? array<array-key, mixed> : mixed)  The same value with each
     *          `EncryptedEnvelope` replaced by a `ProtectedRecordValue` built from its storage spelling;
     *          unchanged when it holds no envelope.
     *
     * @throws  InvalidArgumentException  When an envelope's storage spelling breaches the protected storage
     *          bounds, which a Secret Envelope value cannot do within its own ciphertext limit.
     *
     * @since   2.0.0
     */
    public static function protect(mixed $value): mixed
    {
        if ($value instanceof EncryptedEnvelope) {
            return new ProtectedRecordValue($value->toStorage());
        }
        if (!is_array($value)) {
            return $value;
        }

        return self::substitute($value, 0);
    }

    /**
     * Walk one level of an array, substituting envelopes and descending into arrays within the bound.
     *
     * @param   array<array-key, mixed>  $values  Array at this level.
     * @param   int                      $depth   Nesting level of $values, 0 at the top.
     *
     * @return  array<array-key, mixed>  The array with its envelopes substituted down to the bound.
     *
     * @since   2.0.0
     */
    private static function substitute(array $values, int $depth): array
    {
        if ($depth >= self::MAXIMUM_DEPTH) {
            return $values;
        }
        foreach ($values as $key => $item) {
            if ($item instanceof EncryptedEnvelope) {
                $values[$key] = new ProtectedRecordValue($item->toStorage());
            } elseif (is_array($item)) {
                $values[$key] = self::substitute($item, $depth + 1);
            }
        }

        return $values;
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
