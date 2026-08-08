<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use InvalidArgumentException;

final readonly class EncryptedEnvelope
{
    public const ALGORITHM = 'xchacha20poly1305-ietf';

    public function __construct(
        public string $ciphertext,
        public string $nonce,
        public string $keyId,
        public string $algorithm = self::ALGORITHM,
    ) {
        if ($ciphertext === '' || strlen($ciphertext) > 1_048_576) {
            throw new InvalidArgumentException('An encrypted envelope ciphertext is empty or unbounded.');
        }
        if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new InvalidArgumentException('An encrypted envelope nonce has an invalid size.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,126}$/D', $keyId) !== 1) {
            throw new InvalidArgumentException('An encrypted envelope key identifier is invalid.');
        }
        if ($algorithm !== self::ALGORITHM) {
            throw new InvalidArgumentException('An encrypted envelope algorithm is unsupported.');
        }
    }

    /** @return array{ciphertext: string, nonce: string, key_id: string, algorithm: string} */
    public function toStorage(): array
    {
        return [
            'ciphertext' => base64_encode($this->ciphertext),
            'nonce' => base64_encode($this->nonce),
            'key_id' => $this->keyId,
            'algorithm' => $this->algorithm,
        ];
    }

    /** @param array{ciphertext: string, nonce: string, key_id: string, algorithm: string} $storage */
    public static function fromStorage(array $storage): self
    {
        $ciphertext = base64_decode($storage['ciphertext'], true);
        $nonce = base64_decode($storage['nonce'], true);
        if (!is_string($ciphertext) || !is_string($nonce)) {
            throw new InvalidArgumentException('An encrypted envelope contains invalid base64 data.');
        }

        return new self($ciphertext, $nonce, $storage['key_id'], $storage['algorithm']);
    }
}
