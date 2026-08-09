<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\BusinessRecord\Domain\EncryptedEnvelope;

/**
 * Port through which business-record secret fields are encrypted and decrypted.
 *
 * `RecordValueCodec` reaches for this whenever a field is declared secret, so no secret value ever reaches
 * a column in the clear and the persistence layer never sees a key. Implementations owe authenticated
 * encryption: the associated data built by `SecretAssociatedData` is authenticated but not stored, which
 * is what stops an envelope from being replayed into a different record or field. Keeping this an
 * application-layer port is what lets the key source — libsodium here, a managed KMS elsewhere — change
 * without touching record code.
 *
 * @since  2.0.0
 */
interface SecretCipher
{
    /**
     * Seal a secret value into an envelope bound to the caller's associated data.
     *
     * An implementation must draw a fresh nonce per call, so encrypting the same plaintext twice yields
     * different envelopes and stored ciphertext never reveals that two records hold the same secret.
     *
     * @param   string  $plaintext       Secret value to protect; only the returned envelope is persisted.
     * @param   string  $associatedData  Binding from `SecretAssociatedData`, authenticated but not stored.
     *
     * @return  EncryptedEnvelope  Ciphertext, nonce and key identifier, ready to be written to storage.
     *
     * @since   2.0.0
     */
    public function encrypt(string $plaintext, string $associatedData): EncryptedEnvelope;

    /**
     * Open an envelope and return the secret it protects.
     *
     * Decryption fails closed. An implementation must refuse rather than return a value when the envelope
     * names a key it does not hold, when the ciphertext or nonce has been altered, or when the associated
     * data differs by so much as one byte from the binding used to seal it — which is how an envelope
     * copied between records is caught.
     *
     * @param   EncryptedEnvelope  $envelope        Envelope as read back from storage.
     * @param   string             $associatedData  The same binding that was supplied to `encrypt()`.
     *
     * @return  string  The original plaintext, byte for byte.
     *
     * @since   2.0.0
     */
    public function decrypt(EncryptedEnvelope $envelope, string $associatedData): string;
}
