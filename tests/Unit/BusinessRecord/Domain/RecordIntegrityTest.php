<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRevisionView;
use Kumwe\App\BusinessRecord\Application\RecordFingerprint;
use Kumwe\App\BusinessRecord\Domain\RecordValueProtection;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\Record\Model\BusinessRecordRevision;
use Kumwe\Record\Value\ProtectedRecordValue;
use Kumwe\Secret\Value\EncryptedEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the host half of revision integrity: the checksum bytes over a sealed secret, and the disclosure view.
 *
 * `Kumwe\Record\Model\BusinessRecordRevision` owns the revision and its checksum; what App proves here is its
 * composition around a sealed secret. The revision is constructed over the value map
 * `RecordValueProtection::protect()` returns, because the package guard admits a sealed
 * `Kumwe\Secret\Value\EncryptedEnvelope` only as the `ProtectedRecordValue` built from its storage spelling,
 * and the digests pinned below are the exact checksums the App-owned revision produced over the same input
 * before the aggregate moved to kumwe/record-model, so no stored revision byte has changed. The disclosure
 * view and the request fingerprint stay in App.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordRevisionView::class)]
#[CoversClass(RecordFingerprint::class)]
final class RecordIntegrityTest extends TestCase
{
    /**
     * Checksum of the `create` revision built by `revision()`, as the App-owned revision class produced it.
     *
     * @var    string
     * @since  2.0.0
     */
    private const CREATE_CHECKSUM = '877abce5660e56e3c14274c0a1c520b4d6bcdd4ffcee6b168be2f77bf1e2e3ab';

    /**
     * Checksum of the `update` revision built by `revision()`, as the App-owned revision class produced it.
     *
     * @var    string
     * @since  2.0.0
     */
    private const UPDATE_CHECKSUM = 'a032f9ca06a89cc37208b9498bf068c60c6cf9c13d8773ea80bbbb5a8295b00f';

    /**
     * A revision holding a sealed secret checksums to the bytes it always did, and its view redacts the secret.
     *
     * The pinned digests cover every metadata column and the snapshot, so a moved byte anywhere in the
     * canonical form fails here; the two operations differ in nothing but their label and must not share a
     * digest. The snapshot itself carries the secret as its protected storage, the same four strings the
     * envelope spells, and the view built without a disclosure plan reports the revision's own checksum.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRevisionChecksumCoversMetadataAndDisclosureViewRedactsSecrets(): void
    {
        $secret = new EncryptedEnvelope(
            str_repeat("\x7f", 32),
            str_repeat("\x01", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
            'revision-key-v1',
        );
        $revision = self::revision($secret, 'create');
        $changedOperation = self::revision($secret, 'update');
        $view = BusinessRecordRevisionView::fromRevision(
            $revision,
            EntityTypeDefinition::fromArray(NeutralBusinessFixture::backupDocument()),
        );

        self::assertSame(self::CREATE_CHECKSUM, $revision->checksum());
        self::assertSame(self::UPDATE_CHECKSUM, $changedOperation->checksum());
        self::assertNotSame($revision->checksum(), $changedOperation->checksum());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $revision->checksum());
        $credential = $revision->snapshot()['credential'];
        self::assertInstanceOf(ProtectedRecordValue::class, $credential);
        self::assertSame($secret->toStorage(), $credential->toStorage());
        self::assertArrayNotHasKey('credential', $view->snapshot);
        self::assertNotContains('credential', $view->changedFields);
        self::assertSame('Visible', $view->snapshot['name']);
        self::assertSame($revision->checksum(), $view->integrityChecksum);
    }

    /**
     * The request fingerprint is independent of map order and refuses a PHP float rather than digesting it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFingerprintCanonicalizesMapOrderAndRejectsFloats(): void
    {
        $fingerprints = new RecordFingerprint(str_repeat('f', 32));

        self::assertSame(
            $fingerprints->digest(['name' => 'Alpha', 'amount' => '1.000000']),
            $fingerprints->digest(['amount' => '1.000000', 'name' => 'Alpha']),
        );
        self::assertNotSame(
            $fingerprints->digest(['amount' => '1.000000']),
            $fingerprints->digest(['amount' => '1.000001']),
        );

        $this->expectException(InvalidArgumentException::class);
        $fingerprints->digest(['amount' => 1.0]);
    }

    /**
     * Build one revision over a visible name and a sealed credential, protected the way App hands it to the
     * package constructor.
     *
     * @param   EncryptedEnvelope  $secret     Sealed credential the snapshot carries.
     * @param   string             $operation  Mutation label the revision records.
     *
     * @return  BusinessRecordRevision  The revision, with every other column held constant.
     *
     * @since   2.0.0
     */
    private static function revision(EncryptedEnvelope $secret, string $operation): BusinessRecordRevision
    {
        return new BusinessRecordRevision(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244e20',
            NeutralBusinessFixture::DEFINITION_ID,
            1,
            'default',
            null,
            NeutralBusinessFixture::RECORD_ID,
            str_repeat('a', 64),
            1,
            1,
            $operation,
            RecordValueProtection::protect(['name' => 'Visible', 'credential' => $secret]),
            ['credential', 'name'],
            'unit-actor',
            new DateTimeImmutable('2026-08-08T12:00:00.000000+00:00'),
        );
    }
}
