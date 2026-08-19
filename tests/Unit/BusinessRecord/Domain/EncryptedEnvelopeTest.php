<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Domain;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Domain\EncryptedEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EncryptedEnvelope::class)]
/**
 * Proves a stored envelope is refused before any key is touched when its parts are wrong.
 *
 * The envelope constructor is the single gate a row passes through on the way back out of the database,
 * so every downgrade and corruption case has to be settled here rather than surfacing later as a
 * decryption failure that looks like tampering.
 *
 * @since  2.0.0
 */
final class EncryptedEnvelopeTest extends TestCase
{
    /**
     * Name each malformed stored row together with the rule it breaks.
     *
     * @return  array<string, array{
     *              array{ciphertext: string, nonce: string, key_id: string, algorithm: string},
     *              string,
     *          }>  Damaged storage rows by case name, each with the fragment its rejection must state.
     *
     * @since   2.0.0
     */
    public static function malformedRows(): array
    {
        $nonce = base64_encode(str_repeat("\x11", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES));
        $ciphertext = base64_encode(str_repeat("\x22", 48));

        return [
            'downgraded algorithm' => [
                ['ciphertext' => $ciphertext, 'nonce' => $nonce, 'key_id' => 'k1', 'algorithm' => 'aes-128-ecb'],
                'algorithm is unsupported',
            ],
            'empty algorithm' => [
                ['ciphertext' => $ciphertext, 'nonce' => $nonce, 'key_id' => 'k1', 'algorithm' => ''],
                'algorithm is unsupported',
            ],
            'ciphertext is not base64' => [
                [
                    'ciphertext' => 'not base64!!',
                    'nonce' => $nonce,
                    'key_id' => 'k1',
                    'algorithm' => EncryptedEnvelope::ALGORITHM,
                ],
                'invalid base64 data',
            ],
            'nonce is not base64' => [
                [
                    'ciphertext' => $ciphertext,
                    'nonce' => '****',
                    'key_id' => 'k1',
                    'algorithm' => EncryptedEnvelope::ALGORITHM,
                ],
                'invalid base64 data',
            ],
            'nonce is truncated' => [
                [
                    'ciphertext' => $ciphertext,
                    'nonce' => base64_encode(str_repeat("\x11", 8)),
                    'key_id' => 'k1',
                    'algorithm' => EncryptedEnvelope::ALGORITHM,
                ],
                'nonce has an invalid size',
            ],
            'ciphertext is empty' => [
                [
                    'ciphertext' => '',
                    'nonce' => $nonce,
                    'key_id' => 'k1',
                    'algorithm' => EncryptedEnvelope::ALGORITHM,
                ],
                'empty or unbounded',
            ],
            'key identifier is malformed' => [
                [
                    'ciphertext' => $ciphertext,
                    'nonce' => $nonce,
                    'key_id' => 'key with spaces',
                    'algorithm' => EncryptedEnvelope::ALGORITHM,
                ],
                'key identifier is invalid',
            ],
            'key identifier starts with punctuation' => [
                [
                    'ciphertext' => $ciphertext,
                    'nonce' => $nonce,
                    'key_id' => '-leading-dash',
                    'algorithm' => EncryptedEnvelope::ALGORITHM,
                ],
                'key identifier is invalid',
            ],
            'key identifier is empty' => [
                [
                    'ciphertext' => $ciphertext,
                    'nonce' => $nonce,
                    'key_id' => '',
                    'algorithm' => EncryptedEnvelope::ALGORITHM,
                ],
                'key identifier is invalid',
            ],
        ];
    }

    /**
     * Prove each damaged stored row is refused, and refused for the stated reason.
     *
     * @param   array{ciphertext: string, nonce: string, key_id: string, algorithm: string}  $storage  Row.
     * @param   string                                                                       $expected
     *          Fragment the rejection message must contain.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('malformedRows')]
    public function testMalformedStoredEnvelopesAreRefusedBeforeAnyKeyIsUsed(array $storage, string $expected): void
    {
        try {
            EncryptedEnvelope::fromStorage($storage);
            self::fail('A malformed stored envelope was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($expected, $exception->getMessage());
            foreach ([$storage['ciphertext'], $storage['nonce']] as $bytes) {
                if ($bytes !== '') {
                    self::assertStringNotContainsString($bytes, $exception->getMessage());
                }
            }
        }
    }

    /**
     * Prove the only accepted construction is the one the class names, in both directions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyTheSupportedConstructionSurvivesAStorageRoundTrip(): void
    {
        $envelope = new EncryptedEnvelope(
            str_repeat("\x22", 48),
            str_repeat("\x11", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
            'application-secret-v1',
        );
        $storage = $envelope->toStorage();

        self::assertSame('xchacha20poly1305-ietf', $storage['algorithm']);
        self::assertSame('application-secret-v1', $storage['key_id']);
        self::assertEquals($envelope, EncryptedEnvelope::fromStorage($storage));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('algorithm is unsupported');
        new EncryptedEnvelope($envelope->ciphertext, $envelope->nonce, $envelope->keyId, 'xchacha20poly1305');
    }
}
