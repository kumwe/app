<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessRecord\Infrastructure;

use Kumwe\CMS\BusinessRecord\Domain\EncryptedEnvelope;
use Kumwe\CMS\BusinessRecord\Infrastructure\Security\SodiumSecretCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SodiumSecretCipher::class)]
#[CoversClass(EncryptedEnvelope::class)]
final class SodiumSecretCipherTest extends TestCase
{
    public function testExactEnvelopeRoundTripsAndStorageIsNeverPlaintext(): void
    {
        $cipher = new SodiumSecretCipher('fixture-key-v1', str_repeat("\x7a", 32));
        $envelope = $cipher->encrypt('not-for-history', 'site/definition/record/secret');

        self::assertSame('not-for-history', $cipher->decrypt($envelope, 'site/definition/record/secret'));
        self::assertStringNotContainsString(
            'not-for-history',
            json_encode($envelope->toStorage(), JSON_THROW_ON_ERROR),
        );
        self::assertEquals($envelope, EncryptedEnvelope::fromStorage($envelope->toStorage()));
    }

    public function testAssociatedDataPreventsCrossRecordReplay(): void
    {
        $cipher = new SodiumSecretCipher('fixture-key-v1', str_repeat("\x7a", 32));
        $envelope = $cipher->encrypt('protected', 'record-a');

        $this->expectException(RuntimeException::class);
        $cipher->decrypt($envelope, 'record-b');
    }

    public function testCiphertextTamperingFailsAuthenticatedDecryption(): void
    {
        $cipher = new SodiumSecretCipher('fixture-key-v1', str_repeat("\x7a", 32));
        $envelope = $cipher->encrypt('protected', 'record-a');
        $tampered = new EncryptedEnvelope(
            $envelope->ciphertext ^ str_repeat("\x01", strlen($envelope->ciphertext)),
            $envelope->nonce,
            $envelope->keyId,
        );

        $this->expectException(RuntimeException::class);
        $cipher->decrypt($tampered, 'record-a');
    }
}
