<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Infrastructure\Trust;

use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\PackageSignature;
use Kumwe\CMS\Extension\Infrastructure\Trust\SodiumEd25519Verifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SodiumEd25519Verifier::class)]
final class SodiumEd25519VerifierTest extends TestCase
{
    public function testVerifiesTheSignedHexadecimalChecksum(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $checksum = PackageChecksum::calculate('package');
        $signatureBytes = sodium_crypto_sign_detached((string) $checksum, $secretKey);
        $signature = PackageSignature::ed25519('registry.primary', base64_encode($signatureBytes));
        $verifier = new SodiumEd25519Verifier(['registry.primary' => base64_encode($publicKey)]);

        self::assertTrue($verifier->verify($checksum, $signature));
        self::assertFalse($verifier->verify(PackageChecksum::calculate('tampered'), $signature));
    }
}
