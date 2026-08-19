<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Infrastructure\StepUp;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use InvalidArgumentException;
use Kumwe\App\Identity\Infrastructure\StepUp\DoctrineStepUpCredentialStore;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the second-factor retirement and recovery-code reissue the administrative reset depends on.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineStepUpCredentialStore::class)]
final class DoctrineStepUpCredentialStoreTest extends TestCase
{
    /**
     * Subject whose second factor the retirement tests act on.
     *
     * Both identifiers are patterned rather than random-looking. The adapter treats them as opaque, so
     * nothing here depends on their bytes, and a value carrying no entropy cannot be mistaken for real
     * material by a reader or by the secret scanner that guards the repository.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string SUBJECT = '0191574f-0000-7000-8000-000000000001';

    /**
     * Credential the retirement and reissue tests act on.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string CREDENTIAL = '0191574f-0000-7000-8000-000000000002';

    /**
     * Proves retirement locks the candidates, fences each update and destroys only unspent digests.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRetirementFencesEachCandidateAndDropsOnlyUnspentRecoveryDigests(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAllAssociative')->with(
            self::callback(static function (string $sql): bool {
                self::assertStringContainsString('kumwe_step_up_credentials', $sql);
                self::assertStringContainsString("status IN ('pending', 'active')", $sql);
                self::assertStringContainsString('disabled_at IS NULL', $sql);
                self::assertStringContainsString('FOR UPDATE', $sql);

                return true;
            }),
            [self::SUBJECT],
        )->willReturn([['id' => self::CREDENTIAL, 'version' => 4]]);
        $statements = [];
        $database->expects(self::exactly(2))->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $parameters) use (&$statements): int {
                $statements[] = [$sql, $parameters];

                return 1;
            },
        );

        $retired = $this->store($database)->revokeForSubject(
            self::SUBJECT,
            new DateTimeImmutable('2026-08-13T10:00:00+00:00'),
            'authenticator lost',
        );

        self::assertSame(1, $retired);
        self::assertStringContainsString("status = 'revoked'", $statements[0][0]);
        self::assertStringContainsString('revocation_reason = ?', $statements[0][0]);
        self::assertStringContainsString('version = ?', $statements[0][0]);
        self::assertContains('authenticator lost', $statements[0][1]);
        self::assertContains(4, $statements[0][1]);
        self::assertStringContainsString('DELETE FROM kumwe_step_up_recovery_codes', $statements[1][0]);
        self::assertStringContainsString('consumed_at IS NULL', $statements[1][0]);
    }

    /**
     * Proves a candidate that advanced under the lock aborts the retirement instead of half-applying.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRetirementAbortsWhenACandidateChangedUnderTheLock(): void
    {
        $database = $this->database();
        $database->method('fetchAllAssociative')->willReturn([['id' => self::CREDENTIAL, 'version' => 4]]);
        $database->method('executeStatement')->willReturn(0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('changed while it was being revoked');

        $this->store($database)->revokeForSubject(
            self::SUBJECT,
            new DateTimeImmutable('2026-08-13T10:00:00+00:00'),
            'authenticator lost',
        );
    }

    /**
     * Proves a subject with nothing enrolled is answered with zero rather than a failure.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRetirementOfAnUnenrolledSubjectChangesNothing(): void
    {
        $database = $this->database();
        $database->method('fetchAllAssociative')->willReturn([]);
        $database->expects(self::never())->method('executeStatement');

        self::assertSame(0, $this->store($database)->revokeForSubject(
            self::SUBJECT,
            new DateTimeImmutable('2026-08-13T10:00:00+00:00'),
            'nothing to retire',
        ));
    }

    /**
     * Proves reissue replaces the whole stored set only after the version fence holds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReissueReplacesTheWholeSetBehindTheVersionFence(): void
    {
        $database = $this->database();
        $statements = [];
        $database->expects(self::exactly(2))->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 1;
            },
        );
        $inserted = [];
        $database->expects(self::exactly(2))->method('insert')->willReturnCallback(
            static function (string $table, array $row) use (&$inserted): int {
                self::assertSame('kumwe_step_up_recovery_codes', $table);
                self::assertNull($row['consumed_at']);
                $inserted[] = $row['code_digest'];

                return 1;
            },
        );

        self::assertTrue($this->store($database)->replaceRecoveryCodes(
            self::CREDENTIAL,
            self::SUBJECT,
            7,
            [str_repeat('a', 64), str_repeat('b', 64)],
            new DateTimeImmutable('2026-08-13T10:00:00+00:00'),
        ));
        self::assertStringContainsString('version = version + 1', $statements[0]);
        self::assertStringContainsString("status = 'active'", $statements[0]);
        self::assertStringContainsString('DELETE FROM kumwe_step_up_recovery_codes', $statements[1]);
        self::assertStringNotContainsString('consumed_at IS NULL', $statements[1]);
        self::assertSame([str_repeat('a', 64), str_repeat('b', 64)], $inserted);
    }

    /**
     * Proves a failed version fence writes no digest at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReissueWritesNoDigestWhenTheCredentialAdvancedFirst(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('executeStatement')->willReturn(0);
        $database->expects(self::never())->method('insert');

        self::assertFalse($this->store($database)->replaceRecoveryCodes(
            self::CREDENTIAL,
            self::SUBJECT,
            7,
            [str_repeat('a', 64)],
            new DateTimeImmutable('2026-08-13T10:00:00+00:00'),
        ));
    }

    /**
     * Proves a malformed or duplicated digest is refused before any statement runs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReissueRefusesADuplicatedOrMalformedDigestBeforeWriting(): void
    {
        $database = $this->database();
        $database->expects(self::never())->method('executeStatement');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid or duplicated');

        $this->store($database)->replaceRecoveryCodes(
            self::CREDENTIAL,
            self::SUBJECT,
            7,
            [str_repeat('a', 64), str_repeat('a', 64)],
            new DateTimeImmutable('2026-08-13T10:00:00+00:00'),
        );
    }

    /**
     * Build the adapter with the supplied DBAL test double.
     *
     * @param   Connection  $database  Test double recording the adapter's SQL interactions.
     *
     * @return  DoctrineStepUpCredentialStore  Adapter under test.
     *
     * @since   2.0.0
     */
    private function store(Connection $database): DoctrineStepUpCredentialStore
    {
        return new DoctrineStepUpCredentialStore($database, new TableNames($database, 'kumwe_'));
    }

    /**
     * Create a DBAL test double that runs its transactions inline and quotes readable table names.
     *
     * @return  Connection  Configured DBAL mock reporting a locking platform.
     *
     * @since   2.0.0
     */
    private function database(): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );
        $database->method('getDatabasePlatform')->willReturn($this->createStub(AbstractPlatform::class));
        $database->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return $database;
    }
}
