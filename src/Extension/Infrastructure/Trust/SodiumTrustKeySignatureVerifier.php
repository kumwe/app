<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure\Trust;

use Kumwe\CMS\Extension\Application\Trust\TrustKeySignatureVerifier;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\PackageSignature;

final readonly class SodiumTrustKeySignatureVerifier implements TrustKeySignatureVerifier
{
    public function verify(
        string $publicKeyBase64,
        PackageChecksum $checksum,
        PackageSignature $signature,
    ): bool {
        $publicKey = base64_decode($publicKeyBase64, true);
        $signatureBytes = base64_decode($signature->asBase64(), true);
        if (
            !is_string($publicKey)
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !is_string($signatureBytes)
            || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES
        ) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($signatureBytes, (string) $checksum, $publicKey);
    }
}
