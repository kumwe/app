<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Domain;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\PackageSignature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackageSignature::class)]
final class PackageSignatureTest extends TestCase
{
    public function testDecodesAnEd25519Signature(): void
    {
        $encoded = base64_encode(str_repeat('s', SODIUM_CRYPTO_SIGN_BYTES));
        $signature = PackageSignature::ed25519('registry.primary', $encoded);

        self::assertSame('registry.primary', $signature->keyId());
        self::assertSame('ed25519', $signature->algorithm());
        self::assertSame($encoded, $signature->asBase64());
    }

    public function testRejectsWrongLengthSignatures(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PackageSignature::ed25519('registry.primary', base64_encode('short'));
    }
}
