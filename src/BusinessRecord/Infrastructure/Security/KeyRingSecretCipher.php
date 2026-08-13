<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Infrastructure\Security;

use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Application\SecretCipher;
use Kumwe\CMS\BusinessRecord\Application\SecretKeyProvider;
use Kumwe\CMS\BusinessRecord\Domain\EncryptedEnvelope;
use RuntimeException;

/**
 * `SecretCipher` that seals under the provider's active key and opens under whichever key an envelope names.
 *
 * `SodiumSecretCipher` holds exactly one key, which is correct for the primitive and wrong for a system
 * that has to keep running while its keys change. This wraps it: encryption asks the provider for the
 * active key, decryption asks for the key the stored envelope names, and each call delegates to a
 * single-key cipher bound to that one key. Rotation therefore never means trying keys until one works —
 * exactly one key is ever attempted, chosen by identifier — and an envelope whose key the deployment no
 * longer holds is reported as unavailable rather than as a failed authentication.
 *
 * The algorithm is pinned twice over: `EncryptedEnvelope` refuses to construct anything but the supported
 * construction, and this class re-checks the field it was handed, so a stored row edited to name a weaker
 * construction is refused before a key is touched rather than after.
 *
 * @since  2.0.0
 */
final readonly class KeyRingSecretCipher implements SecretCipher
{
    /**
     * Bind the cipher to the provider that answers for key material.
     *
     * @param  SecretKeyProvider  $keys  Source of the active key and of retired keys by identifier.
     *
     * @since  2.0.0
     */
    public function __construct(private SecretKeyProvider $keys)
    {
    }

    /**
     * Seal a secret under the provider's active key.
     *
     * @param   string  $plaintext       Secret value to protect, at most 1,000,000 bytes.
     * @param   string  $associatedData  Binding authenticated alongside the ciphertext but not stored, at
     *          most 4096 bytes; the same string must be supplied again to decrypt.
     *
     * @return  EncryptedEnvelope  Ciphertext, its fresh nonce, and the active key identifier.
     *
     * @throws  InvalidArgumentException  When the plaintext or the associated data exceeds its bound.
     * @throws  RuntimeException  When libsodium refuses the encryption.
     * @throws  \Kumwe\CMS\BusinessRecord\Domain\SecretKeyUnavailable  When the provider cannot produce
     *          its own active key, which stops the write rather than degrading it.
     *
     * @since   2.0.0
     */
    public function encrypt(string $plaintext, string $associatedData): EncryptedEnvelope
    {
        $key = $this->keys->activeKey();

        return (new SodiumSecretCipher($key->keyId, $key->material()))->encrypt($plaintext, $associatedData);
    }

    /**
     * Open an envelope under the one key its identifier names.
     *
     * @param   EncryptedEnvelope  $envelope        Stored ciphertext with its nonce and key identifier.
     * @param   string             $associatedData  The binding used at encryption time; any difference
     *          fails authentication.
     *
     * @return  string  The original plaintext, byte for byte.
     *
     * @throws  RuntimeException  When the envelope names an unsupported construction, libsodium refuses
     *          the input, or the ciphertext fails authentication.
     * @throws  \Kumwe\CMS\BusinessRecord\Domain\SecretKeyUnavailable  When the envelope names a key this
     *          deployment does not hold, which is a distinct condition from a failed authentication.
     *
     * @since   2.0.0
     */
    public function decrypt(EncryptedEnvelope $envelope, string $associatedData): string
    {
        if ($envelope->algorithm !== EncryptedEnvelope::ALGORITHM) {
            throw new RuntimeException('The business-record secret envelope names an unsupported algorithm.');
        }
        $key = $this->keys->keyFor($envelope->keyId);

        return (new SodiumSecretCipher($key->keyId, $key->material()))->decrypt($envelope, $associatedData);
    }
}
