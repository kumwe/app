<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Infrastructure;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Domain\EncryptedEnvelope;
use Kumwe\App\BusinessRecord\Infrastructure\Security\SodiumSecretCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SodiumSecretCipher::class)]
#[CoversClass(EncryptedEnvelope::class)]
final class SodiumSecretCipherTest extends TestCase
{
    /**
     * Plaintext no failure message, and no stored column, is ever allowed to contain.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PLAINTEXT = 'correct-horse-battery-staple';

    /**
     * Key bytes no failure message is ever allowed to contain, in any spelling.
     *
     * Assembled from a readable stem rather than written out, for the reason SecretKeyLifecycleTest gives:
     * no line of this file should resemble a credential to a secret scanner, and a reader should be able to
     * see at a glance that nothing here was ever real key material.
     *
     * @return  string  Deterministic 32-byte fixture key.
     *
     * @since   2.0.0
     */
    private static function key(): string
    {
        return substr(str_repeat('fixture-key-', 3), 0, 32);
    }

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

    /**
     * Prove a foreign key identifier is reported as an unavailable key, not as a broken ciphertext.
     *
     * The distinction is the point: an operator reading "unavailable" restores a key, while an operator
     * reading "failed authentication" starts a tampering investigation. Getting them the wrong way round
     * wastes an incident response either way.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEnvelopeFromAnotherKeyIsUnavailableRatherThanUnauthenticated(): void
    {
        $writer = new SodiumSecretCipher('retired-key-v1', str_repeat("\x7a", 32));
        $reader = new SodiumSecretCipher('active-key-v2', str_repeat("\x7a", 32));
        $envelope = $writer->encrypt(self::PLAINTEXT, 'record-a');

        try {
            $reader->decrypt($envelope, 'record-a');
            self::fail('An envelope sealed under a foreign key was opened.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('key is unavailable', $exception->getMessage());
            self::assertStringNotContainsString('authenticated decryption', $exception->getMessage());
        }
    }

    /**
     * Prove a ciphertext cut short fails authentication instead of returning a shortened secret.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATruncatedCiphertextFailsClosed(): void
    {
        $cipher = new SodiumSecretCipher('fixture-key-v1', str_repeat("\x7a", 32));
        $envelope = $cipher->encrypt(self::PLAINTEXT, 'record-a');
        $truncated = new EncryptedEnvelope(
            substr($envelope->ciphertext, 0, strlen($envelope->ciphertext) - 4),
            $envelope->nonce,
            $envelope->keyId,
        );

        $this->expectException(RuntimeException::class);
        $cipher->decrypt($truncated, 'record-a');
    }

    /**
     * Prove the cipher refuses to bind to a malformed identifier or a key of the wrong size.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCipherRefusesAMalformedKeyIdentifierOrAnUnusableKey(): void
    {
        try {
            new SodiumSecretCipher('key with spaces', str_repeat("\x7a", 32));
            self::fail('A malformed key identifier was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('key identifier is invalid', $exception->getMessage());
        }

        try {
            new SodiumSecretCipher('fixture-key-v1', str_repeat("\x7a", 16));
            self::fail('An undersized key was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('invalid size', $exception->getMessage());
        }
    }

    /**
     * Prove no failure path puts the plaintext or the key into the message it reports.
     *
     * Every one of these is a message an operator, a log shipper, or an error tracker may see, which is
     * why the assertion is made once over the whole set rather than case by case.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoFailureMessageCarriesPlaintextOrKeyMaterial(): void
    {
        $cipher = new SodiumSecretCipher('fixture-key-v1', self::key());
        $envelope = $cipher->encrypt(self::PLAINTEXT, 'record-a');
        $messages = [];
        $attempts = [
            fn (): string => $cipher->decrypt($envelope, 'record-b'),
            fn (): string => (new SodiumSecretCipher('other-key-v1', self::key()))->decrypt($envelope, 'record-a'),
            fn (): string => $cipher->decrypt(
                new EncryptedEnvelope(substr($envelope->ciphertext, 0, 20), $envelope->nonce, $envelope->keyId),
                'record-a',
            ),
            fn (): string => $cipher->encrypt(str_repeat('x', 1_000_001), 'record-a')->ciphertext,
            fn (): string => $cipher->encrypt(self::PLAINTEXT, str_repeat('a', 4097))->ciphertext,
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('A fail-closed path returned a value.');
            } catch (RuntimeException | InvalidArgumentException $exception) {
                $messages[] = $exception->getMessage() . ' ' . $exception->getTraceAsString();
            }
        }

        self::assertCount(5, $messages);
        foreach ($messages as $message) {
            self::assertStringNotContainsString(self::PLAINTEXT, $message);
            self::assertStringNotContainsString(self::key(), $message);
            self::assertStringNotContainsString(bin2hex(self::key()), $message);
            self::assertStringNotContainsString(base64_encode(self::key()), $message);
        }
    }
}
