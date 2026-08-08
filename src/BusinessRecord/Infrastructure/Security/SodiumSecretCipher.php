<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Infrastructure\Security;

use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Application\SecretCipher;
use Kumwe\CMS\BusinessRecord\Domain\EncryptedEnvelope;
use RuntimeException;
use SodiumException;

final readonly class SodiumSecretCipher implements SecretCipher
{
    public function __construct(private string $keyId, private string $key)
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,126}$/D', $keyId) !== 1) {
            throw new InvalidArgumentException('The business-record encryption key identifier is invalid.');
        }
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidArgumentException('The business-record encryption key has an invalid size.');
        }
    }

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
