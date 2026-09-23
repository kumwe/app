<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Domain;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Domain\RecordValueProtection;
use Kumwe\Record\Value\ProtectedRecordValue;
use Kumwe\Record\Value\RecordValueGuard;
use Kumwe\Secret\Value\EncryptedEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the host adapter that stands between a sealed secret and the package record-value guard.
 *
 * kumwe/record-values owns admission and canonical spelling and refuses a `Kumwe\Secret\Value\EncryptedEnvelope`
 * as an unsupported runtime type; App keeps cryptography and substitutes the opaque protected storage the guard
 * admits. What is proven here is the composition: an envelope is admitted wherever App feeds one to the guard,
 * its canonical bytes are exactly the storage spelling App checksummed before the guard moved, a value without
 * an envelope passes through unchanged, and the App boundary still refuses a PHP float. That float assertion is
 * the host assertion the retired RecordValueGuardTest held when kumwe/conversion 0.1.5 was adopted
 * (KUMWE-MIG-2026-031); it moved here when the guard itself moved to the package (KUMWE-MIG-2026-029).
 *
 * @since  2.0.0
 */
#[CoversClass(RecordValueProtection::class)]
final class RecordValueProtectionTest extends TestCase
{
    /**
     * A sealed envelope is admitted by the guard and canonicalised as its unchanged storage spelling.
     *
     * The expected arrays are the envelope's own `toStorage()` in its own member order, at the top of a value
     * and nested inside a snapshot map, because those are the bytes every stored checksum was taken over.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASealedEnvelopeIsAdmittedAndCanonicalisedAsItsStorageSpelling(): void
    {
        $envelope = self::envelope();
        $protected = RecordValueProtection::protect($envelope);

        self::assertInstanceOf(ProtectedRecordValue::class, $protected);
        RecordValueGuard::assertValue($protected);
        self::assertSame($envelope->toStorage(), RecordValueGuard::canonical($protected));
        self::assertSame(
            ['credential' => $envelope->toStorage(), 'name' => 'Visible'],
            RecordValueGuard::canonical(
                RecordValueProtection::protect(['name' => 'Visible', 'credential' => $envelope]),
            ),
        );
        self::assertSame(
            [
                'revision_id' => 'unit-revision',
                'snapshot' => ['credential' => $envelope->toStorage(), 'name' => 'Visible'],
            ],
            RecordValueGuard::canonical(RecordValueProtection::protect([
                'snapshot' => ['name' => 'Visible', 'credential' => $envelope],
                'revision_id' => 'unit-revision',
            ])),
        );
    }

    /**
     * A value that holds no envelope comes back as it went in, so nothing else the guard sees is altered.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAValueWithoutAnEnvelopePassesThroughUntouched(): void
    {
        $value = ['amount' => '1.000000', 'lines' => [['qty' => 1, 'note' => null]], 'flag' => true];

        self::assertSame($value, RecordValueProtection::protect($value));
        self::assertSame('text', RecordValueProtection::protect('text'));
        self::assertNull(RecordValueProtection::protect(null));
    }

    /**
     * A PHP float is refused at the App boundary, at the top of a value and nested beside a sealed secret alike.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPhpFloatIsStillRefusedAtTheAppBoundary(): void
    {
        try {
            RecordValueGuard::assertValue(RecordValueProtection::protect([
                'credential' => self::envelope(),
                'amount' => ['unrounded' => 0.1],
            ]));
            self::fail('A float nested inside a record value must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('floats', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('floats');
        RecordValueGuard::canonical(RecordValueProtection::protect(0.1));
    }

    /**
     * A structure deeper than the guard admits is refused by the guard rather than walked any further.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStructureBeyondTheGuardsDepthBoundIsRefusedNotWalked(): void
    {
        $value = self::envelope();
        for ($depth = 0; $depth < 10; ++$depth) {
            $value = ['nested' => $value];
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('structural bounds');
        RecordValueGuard::assertValue(RecordValueProtection::protect($value));
    }

    /**
     * Build one sealed envelope from readable stems; no cryptography is exercised here.
     *
     * @return  EncryptedEnvelope  An envelope of the minimum ciphertext length under a unit key identifier.
     *
     * @since   2.0.0
     */
    private static function envelope(): EncryptedEnvelope
    {
        return new EncryptedEnvelope(
            str_repeat("\x7f", 32),
            str_repeat("\x01", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
            'unit-key-v1',
        );
    }
}
