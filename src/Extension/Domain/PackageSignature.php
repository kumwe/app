<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

use InvalidArgumentException;

final readonly class PackageSignature
{
    /** @param non-empty-string $bytes */
    private function __construct(
        private string $keyId,
        private string $bytes,
    ) {
    }

    public static function ed25519(string $keyId, string $base64Signature): self
    {
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{2,126}$/D', $keyId) !== 1) {
            throw new InvalidArgumentException('A signature key ID must be a stable lowercase identifier.');
        }

        $bytes = base64_decode($base64Signature, true);

        if (!is_string($bytes) || strlen($bytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new InvalidArgumentException('An Ed25519 signature must contain exactly 64 bytes.');
        }

        /** @var non-empty-string $bytes */
        return new self($keyId, $bytes);
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function algorithm(): string
    {
        return 'ed25519';
    }

    /** @return non-empty-string */
    public function bytes(): string
    {
        return $this->bytes;
    }

    public function asBase64(): string
    {
        return base64_encode($this->bytes);
    }
}
