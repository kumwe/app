<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Infrastructure\StepUp;

use InvalidArgumentException;
use Kumwe\App\Identity\Application\StepUp\StepUpRejected;
use Kumwe\App\Identity\Application\StepUp\StepUpSecretCipher;
use SodiumException;

/**
 * XChaCha20-Poly1305 authenticated encryption for persisted authenticator secrets.
 *
 * @since  2.0.0
 */
final readonly class SodiumStepUpSecretCipher implements StepUpSecretCipher
{
    /**
     * Bind the cipher to a dedicated 256-bit installation key.
     *
     * @param   string  $key  Raw key bytes from the installation secret provider.
     *
     * @throws  InvalidArgumentException  When the key is not exactly 32 bytes.
     *
     * @since   2.0.0
     */
    public function __construct(private string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidArgumentException('The step-up encryption key must contain exactly 32 bytes.');
        }
    }

    /**
     * Seal plaintext under a fresh 192-bit nonce and a credential binding.
     *
     * @param   string  $plaintext       Raw TOTP secret.
     * @param   string  $associatedData  Stable credential and subject binding.
     *
     * @return  string  `v1.` plus URL-safe Base64 of nonce and ciphertext.
     *
     * @since   2.0.0
     */
    public function encrypt(string $plaintext, string $associatedData): string
    {
        if ($plaintext === '' || $associatedData === '') {
            throw new InvalidArgumentException('Step-up secret plaintext and associated data are required.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $associatedData,
            $nonce,
            $this->key,
        );

        return 'v1.' . self::base64UrlEncode($nonce . $ciphertext);
    }

    /**
     * Authenticate and open a version-one envelope.
     *
     * @param   string  $ciphertext      Stored envelope.
     * @param   string  $associatedData  Expected credential and subject binding.
     *
     * @return  string  Original raw secret.
     *
     * @throws  StepUpRejected  When the envelope, binding, or authentication tag is invalid.
     *
     * @since   2.0.0
     */
    public function decrypt(string $ciphertext, string $associatedData): string
    {
        if (!str_starts_with($ciphertext, 'v1.') || $associatedData === '') {
            throw new StepUpRejected();
        }
        $decoded = self::base64UrlDecode(substr($ciphertext, 3));
        $minimum = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES + 1;
        if ($decoded === null || strlen($decoded) < $minimum) {
            throw new StepUpRejected();
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $sealed = substr($decoded, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $sealed,
                $associatedData,
                $nonce,
                $this->key,
            );
        } catch (SodiumException) {
            throw new StepUpRejected();
        }
        if (!is_string($plaintext) || $plaintext === '') {
            throw new StepUpRejected();
        }

        return $plaintext;
    }

    /**
     * Encode bytes without padding for a text storage column.
     *
     * @param   string  $value  Raw bytes.
     *
     * @return  string  URL-safe Base64 without padding.
     *
     * @since   2.0.0
     */
    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Decode strict unpadded URL-safe Base64.
     *
     * @param   string  $value  Encoded text.
     *
     * @return  ?string  Decoded bytes or null when malformed.
     *
     * @since   2.0.0
     */
    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            return null;
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);

        return is_string($decoded) ? $decoded : null;
    }
}
