<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure\Trust;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Application\Trust\PackageSignatureVerifier;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\PackageSignature;

final readonly class SodiumEd25519Verifier implements PackageSignatureVerifier
{
    /** @var array<string, non-empty-string> */
    private array $publicKeys;

    /** @param array<mixed> $base64PublicKeys Keyed by signing key ID. */
    public function __construct(array $base64PublicKeys)
    {
        $keys = [];

        foreach ($base64PublicKeys as $keyId => $base64PublicKey) {
            if (!is_string($keyId) || !is_string($base64PublicKey)) {
                throw new InvalidArgumentException('Signing keys must map string IDs to base64 public keys.');
            }

            $publicKey = base64_decode($base64PublicKey, true);

            if (!is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new InvalidArgumentException('Every Ed25519 public key must contain exactly 32 bytes.');
            }

            /** @var non-falsy-string $publicKey */
            $keys[$keyId] = $publicKey;
        }

        /** @var array<string, non-empty-string> $keys */
        $this->publicKeys = $keys;
    }

    public function verify(PackageChecksum $checksum, PackageSignature $signature): bool
    {
        $publicKey = $this->publicKeys[$signature->keyId()] ?? null;

        return $publicKey !== null
            && sodium_crypto_sign_verify_detached($signature->bytes(), (string) $checksum, $publicKey);
    }
}
