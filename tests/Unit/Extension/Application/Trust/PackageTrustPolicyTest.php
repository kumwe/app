<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Application\Trust;

use Kumwe\Extension\Package\PackageSignatureVerifier;
use Kumwe\App\Extension\Application\Trust\PackageTrustPolicy;
use Kumwe\App\Extension\Application\Trust\UntrustedPackage;
use Kumwe\Extension\Package\PackageChecksum;
use Kumwe\Extension\Package\PackageSignature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackageTrustPolicy::class)]
final class PackageTrustPolicyTest extends TestCase
{
    public function testRequiresAValidSignatureFromATrustedKey(): void
    {
        $signature = $this->signature();
        $verifier = new class implements PackageSignatureVerifier {
            public function verify(PackageChecksum $checksum, PackageSignature $signature): bool
            {
                return true;
            }
        };

        (new PackageTrustPolicy($verifier, ['registry.primary']))
            ->assertTrusted(PackageChecksum::calculate('package'), $signature, false);
        self::addToAssertionCount(1);
    }

    public function testRejectsUnknownSigningKeys(): void
    {
        $verifier = new class implements PackageSignatureVerifier {
            public function verify(PackageChecksum $checksum, PackageSignature $signature): bool
            {
                return true;
            }
        };

        $this->expectException(UntrustedPackage::class);

        (new PackageTrustPolicy($verifier, ['registry.secondary']))
            ->assertTrusted(PackageChecksum::calculate('package'), $this->signature(), false);
    }

    public function testUnsignedPackagesRequireAnExplicitLocalPolicy(): void
    {
        $verifier = new class implements PackageSignatureVerifier {
            public function verify(PackageChecksum $checksum, PackageSignature $signature): bool
            {
                return false;
            }
        };

        (new PackageTrustPolicy($verifier, [], true))
            ->assertTrusted(PackageChecksum::calculate('local package'), null, true);
        self::addToAssertionCount(1);
    }

    private function signature(): PackageSignature
    {
        return PackageSignature::ed25519(
            'registry.primary',
            base64_encode(str_repeat('s', SODIUM_CRYPTO_SIGN_BYTES)),
        );
    }
}
