<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessRecord\Application\RecordValueCodec;
use Kumwe\App\BusinessRecord\Application\SecretAssociatedData;
use Kumwe\App\BusinessRecord\Domain\EncryptedEnvelope;
use Kumwe\App\BusinessRecord\Domain\ExactDecimal;
use Kumwe\App\BusinessRecord\Domain\MoneyValue;
use Kumwe\App\BusinessRecord\Domain\QuantityValue;
use Kumwe\App\BusinessRecord\Domain\ZonedDateTimeValue;
use Kumwe\App\BusinessRecord\Infrastructure\Security\SodiumSecretCipher;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ExactDecimal::class)]
#[CoversClass(MoneyValue::class)]
#[CoversClass(QuantityValue::class)]
#[CoversClass(ZonedDateTimeValue::class)]
#[CoversClass(EncryptedEnvelope::class)]
#[CoversClass(RecordValueCodec::class)]
#[CoversClass(SecretAssociatedData::class)]
#[CoversClass(SodiumSecretCipher::class)]
final class ExactValueCodecTest extends TestCase
{
    public function testMaximumPrecisionAndScaleRemainExactAndFloatsAreRejected(): void
    {
        $fraction = str_repeat('9', 65);
        $value = ExactDecimal::fromString('0.' . $fraction, 65, 65);

        self::assertSame('0.' . $fraction, $value->value());
        self::assertSame(
            1,
            $value->compare(ExactDecimal::fromString('0.' . str_repeat('8', 65), 65, 65)),
        );
        self::assertSame('0.' . str_repeat('0', 65), ExactDecimal::fromString('-0', 65, 65)->value());

        try {
            ExactDecimal::fromString('0.' . str_repeat('1', 66), 65, 65);
            self::fail('A value exceeding scale 65 must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('fractional digits', $exception->getMessage());
        }

        try {
            ExactDecimal::fromString('1', 65, 65);
            self::fail('A precision-65 scale-65 value cannot have an integer digit.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('precision', $exception->getMessage());
        }

        $codec = self::codec();
        try {
            $codec->normalize(self::field('amount'), 0.1, 'default', NeutralBusinessFixture::DEFINITION_ID, 'row');
            self::fail('A PHP float must never enter exact record storage.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('floats', $exception->getMessage());
        }
    }

    public function testMoneyQuantityAndTemporalValuesNormalizeWithoutLoss(): void
    {
        $codec = self::codec();
        $definitionId = NeutralBusinessFixture::DEFINITION_ID;
        $recordKey = NeutralBusinessFixture::RECORD_ID;

        $amount = $codec->normalize(self::field('amount'), '123.450001', 'default', $definitionId, $recordKey);
        $money = $codec->normalize(
            self::field('price'),
            ['amount' => '19.990000', 'currency' => 'nad'],
            'default',
            $definitionId,
            $recordKey,
        );
        $quantity = $codec->normalize(
            self::field('quantity'),
            ['amount' => '7.125000', 'unit' => 'unit'],
            'default',
            $definitionId,
            $recordKey,
        );
        $date = $codec->normalize(
            self::field('service_date'),
            '2026-08-08',
            'default',
            $definitionId,
            $recordKey,
        );
        $time = $codec->normalize(
            self::field('local_time'),
            '13:14:15.123456',
            'default',
            $definitionId,
            $recordKey,
        );
        $instant = $codec->normalize(
            self::field('recorded_at'),
            '2026-08-08T11:14:15.123456Z',
            'default',
            $definitionId,
            $recordKey,
        );
        $zoned = $codec->normalize(
            self::field('scheduled_for'),
            ['instant' => '2026-08-08T11:14:15.123456Z', 'timezone' => 'Africa/Windhoek'],
            'default',
            $definitionId,
            $recordKey,
        );

        self::assertInstanceOf(ExactDecimal::class, $amount);
        self::assertSame('123.450001000000000000000000000000', $amount->value());
        self::assertInstanceOf(MoneyValue::class, $money);
        self::assertSame([
            'amount' => '19.990000000000000000000000000000',
            'currency' => 'NAD',
        ], $money->toArray());
        self::assertInstanceOf(QuantityValue::class, $quantity);
        self::assertSame([
            'amount' => '7.125000000000000000000000000000',
            'unit' => 'unit',
        ], $quantity->toArray());
        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2026-08-08', $date->format('Y-m-d'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        self::assertSame('13:14:15.123456', $time->format('H:i:s.u'));
        self::assertInstanceOf(DateTimeImmutable::class, $instant);
        self::assertSame('+00:00', $instant->format('P'));
        self::assertInstanceOf(ZonedDateTimeValue::class, $zoned);
        self::assertSame('Africa/Windhoek', $zoned->timezone);
        self::assertSame('2026-08-08T11:14:15.123456Z', $zoned->toArray()['instant']);
    }

    public function testSecretEncryptionAuthenticatesCiphertextAndRecordContext(): void
    {
        $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $cipher = new SodiumSecretCipher('unit-key-v1', $key);
        $codec = new RecordValueCodec($cipher);
        $associatedData = SecretAssociatedData::for(
            'default',
            NeutralBusinessFixture::DEFINITION_ID,
            NeutralBusinessFixture::RECORD_ID,
            'credential',
        );
        $envelope = $codec->normalize(
            self::field('credential'),
            'plaintext-must-not-survive',
            'default',
            NeutralBusinessFixture::DEFINITION_ID,
            NeutralBusinessFixture::RECORD_ID,
        );

        self::assertInstanceOf(EncryptedEnvelope::class, $envelope);
        self::assertStringNotContainsString('plaintext-must-not-survive', $envelope->ciphertext);
        self::assertSame('plaintext-must-not-survive', $cipher->decrypt($envelope, $associatedData));

        $tampered = new EncryptedEnvelope(
            chr(ord($envelope->ciphertext[0]) ^ 1) . substr($envelope->ciphertext, 1),
            $envelope->nonce,
            $envelope->keyId,
        );
        try {
            $cipher->decrypt($tampered, $associatedData);
            self::fail('Authenticated encryption must reject changed ciphertext.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('authenticated decryption', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $cipher->decrypt($envelope, $associatedData . "\nchanged-record");
    }

    public function testIntegerCodecUsesThePortableSignedDatabaseRange(): void
    {
        $codec = self::codec();
        $field = new FieldDefinition('sequence', 'Sequence', 'core.integer');

        self::assertSame(
            -2_147_483_648,
            $codec->normalize($field, -2_147_483_648, 'default', NeutralBusinessFixture::DEFINITION_ID, 'row'),
        );
        self::assertSame(
            2_147_483_647,
            $codec->normalize($field, 2_147_483_647, 'default', NeutralBusinessFixture::DEFINITION_ID, 'row'),
        );
        foreach ([-2_147_483_649, 2_147_483_648] as $outside) {
            try {
                $codec->normalize($field, $outside, 'default', NeutralBusinessFixture::DEFINITION_ID, 'row');
                self::fail('An integer outside the signed 32-bit range was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('signed 32-bit', $exception->getMessage());
            }
        }
    }

    public function testTemporalCodecsRejectYearsOutsideThePortableDatabaseRange(): void
    {
        $codec = self::codec();
        foreach (
            [
            ['service_date', '0999-12-31'],
            ['recorded_at', '0999-12-31T23:59:59.999999Z'],
            ['scheduled_for', [
                'instant' => '0999-12-31T23:59:59.999999Z',
                'timezone' => 'Africa/Windhoek',
            ]],
            ] as [$handle, $value]
        ) {
            try {
                $codec->normalize(
                    self::field($handle),
                    $value,
                    'default',
                    NeutralBusinessFixture::DEFINITION_ID,
                    'row',
                );
                self::fail('A temporal value before year 1000 was accepted for ' . $handle . '.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('portable', $exception->getMessage());
            }
        }

        $minimum = $codec->normalize(
            self::field('recorded_at'),
            '1000-01-01T00:00:00.000000Z',
            'default',
            NeutralBusinessFixture::DEFINITION_ID,
            'row',
        );
        self::assertSame('1000-01-01', $minimum->format('Y-m-d'));
    }

    private static function codec(): RecordValueCodec
    {
        return new RecordValueCodec(new SodiumSecretCipher(
            'unit-key-v1',
            str_repeat("\x5a", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        ));
    }

    private static function field(string $handle): FieldDefinition
    {
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::backupDocument());
        foreach ($definition->fields() as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }

        self::fail('The neutral fixture field ' . $handle . ' is unavailable.');
    }
}
