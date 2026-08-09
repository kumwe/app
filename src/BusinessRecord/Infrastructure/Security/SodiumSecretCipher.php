<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Infrastructure\Security;

use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Application\SecretCipher;
use Kumwe\CMS\BusinessRecord\Domain\EncryptedEnvelope;
use RuntimeException;
use SodiumException;

/**
 * `SecretCipher` backed by libsodium's XChaCha20-Poly1305 AEAD, holding exactly one key.
 *
 * Secret business-record fields are sealed here before they reach any column. The instance is bound to
 * a single key and the identifier that names it, which it stamps into every envelope it writes and
 * insists on again before decrypting: an envelope written under a different key is reported as
 * unavailable rather than attempted, so rotation means wiring a second instance and never means trying
 * keys until one works. Each encryption draws a fresh random nonce, and the caller's associated data —
 * `SecretAssociatedData` builds it from the site, definition, record and field — is authenticated but
 * not stored, so a ciphertext copied into another cell no longer opens.
 *
 * @since  2.0.0
 */
final readonly class SodiumSecretCipher implements SecretCipher
{
    /**
     * Bind the cipher to one key and check that both halves of the binding are usable.
     *
     * @param   string  $keyId  Name of the key, recorded in every envelope and matched on decryption; an
     *          alphanumeric first character followed by up to 126 more of `A-Za-z0-9._:-`.
     * @param   string  $key    Raw XChaCha20-Poly1305 key material of exactly the algorithm's key length,
     *          as derived by the container from the application secret — not a passphrase or hex text.
     *
     * @throws  InvalidArgumentException  When the identifier does not match the accepted shape, or the key
     *          is not exactly the algorithm's key size.
     *
     * @since   2.0.0
     */
    public function __construct(private string $keyId, private string $key)
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,126}$/D', $keyId) !== 1) {
            throw new InvalidArgumentException('The business-record encryption key identifier is invalid.');
        }
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidArgumentException('The business-record encryption key has an invalid size.');
        }
    }

    /**
     * Seal a secret under this instance's key with a nonce drawn fresh for this call.
     *
     * Both inputs are bounded first, so an oversized field is refused before any key material is touched,
     * and the plaintext bound is what keeps the resulting ciphertext inside the envelope's own limit.
     *
     * @param   string  $plaintext       Secret value to protect, at most 1,000,000 bytes.
     * @param   string  $associatedData  Binding authenticated alongside the ciphertext but not stored, at
     *          most 4096 bytes; the same string must be supplied again to decrypt.
     *
     * @return  EncryptedEnvelope  Ciphertext, the nonce it was sealed with, and this instance's key
     *          identifier, ready to persist.
     *
     * @throws  InvalidArgumentException  When the plaintext or the associated data exceeds its bound.
     * @throws  RuntimeException  When libsodium refuses the encryption.
     *
     * @since   2.0.0
     */
    public function encrypt(string $plaintext, string $associatedData): EncryptedEnvelope
    {
        if (strlen($plaintext) > 1_000_000 || strlen($associatedData) > 4096) {
            throw new InvalidArgumentException('The secret value or associated data exceeds its safe bound.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $associatedData,
                $nonce,
                $this->key,
            );
        } catch (SodiumException $exception) {
            throw new RuntimeException('The business-record secret could not be encrypted.', 0, $exception);
        }

        return new EncryptedEnvelope($ciphertext, $nonce, $this->keyId);
    }

    /**
     * Open an envelope this instance's key sealed and hand back the secret.
     *
     * The key identifier is compared first, in constant time, so an envelope from a rotated or foreign
     * key fails as an unavailable key instead of as a decryption error. Authentication then covers the
     * associated data, which is what makes a ciphertext moved to another cell unreadable.
     *
     * @param   EncryptedEnvelope  $envelope        Stored ciphertext with its nonce and the identifier of
     *          the key that sealed it.
     * @param   string             $associatedData  The binding used at encryption time; any difference
     *          fails authentication.
     *
     * @return  string  The original plaintext, byte for byte.
     *
     * @throws  RuntimeException  When the envelope names another key, libsodium refuses the input, or the
     *          ciphertext fails authentication.
     *
     * @since   2.0.0
     */
    public function decrypt(EncryptedEnvelope $envelope, string $associatedData): string
    {
        if (!hash_equals($this->keyId, $envelope->keyId)) {
            throw new RuntimeException('The business-record secret encryption key is unavailable.');
        }
        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $envelope->ciphertext,
                $associatedData,
                $envelope->nonce,
                $this->key,
            );
        } catch (SodiumException $exception) {
            throw new RuntimeException('The business-record secret could not be decrypted.', 0, $exception);
        }
        if (!is_string($plaintext)) {
            throw new RuntimeException('The business-record secret failed authenticated decryption.');
        }

        return $plaintext;
    }
}
