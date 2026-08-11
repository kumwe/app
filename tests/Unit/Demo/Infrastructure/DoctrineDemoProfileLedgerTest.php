<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Demo\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Kumwe\CMS\Demo\Infrastructure\Persistence\DoctrineDemoProfileLedger;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Proves the durable demo-profile selector rejects ambiguous manifest evolution.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineDemoProfileLedger::class)]
final class DoctrineDemoProfileLedgerTest extends TestCase
{
    /**
     * First deterministic canonical manifest digest used by the evolution scenarios.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string CHECKSUM_ONE = '1111111111111111111111111111111111111111111111111111111111111111';

    /**
     * Distinct deterministic digest representing altered manifest bytes.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string CHECKSUM_TWO = '2222222222222222222222222222222222222222222222222222222222222222';

    /**
     * Prove a maximum-length site still produces one database-scoped MySQL lock within 64 characters.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdvisoryLockIdentityIsBoundedAndStable(): void
    {
        $database = $this->database();
        $database->method('getDatabasePlatform')->willReturn(new MySQL84Platform());
        $lockNames = [];
        $database->method('fetchOne')->willReturnCallback(
            static function (string $query, array $parameters = []) use (&$lockNames): int|string {
                if ($query === 'SELECT DATABASE()') {
                    return 'kumwe_customer_database';
                }
                if ($query === 'SELECT GET_LOCK(?, 0)' || $query === 'SELECT RELEASE_LOCK(?)') {
                    $lockName = $parameters[0] ?? null;
                    self::assertIsString($lockName);
                    $lockNames[] = $lockName;

                    return 1;
                }

                self::fail(sprintf('Unexpected advisory-lock query: %s', $query));
            },
        );

        self::assertSame('completed', $this->ledger($database)->synchronized(
            's' . str_repeat('x', 190),
            static fn (): string => 'completed',
        ));
        self::assertCount(2, $lockNames);
        self::assertSame($lockNames[0], $lockNames[1]);
        self::assertStringStartsWith('kumwe:demo:', $lockNames[0]);
        self::assertLessThanOrEqual(64, strlen($lockNames[0]));
    }

    /**
     * Prove released bytes cannot change while retaining an already persisted manifest version.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSameVersionChecksumChangeIsRejectedWithoutMutation(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->willReturn(
            $this->installation(self::CHECKSUM_ONE, 3, 'complete'),
        );
        $database->expects(self::never())->method('update');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest version 3 changed without a version increment');

        $this->ledger($database)->begin(
            'default',
            'site-content',
            'documentation',
            3,
            self::CHECKSUM_TWO,
        );
    }

    /**
     * Prove the exact completed manifest remains an idempotent no-op.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSameVersionAndChecksumRemainIdempotent(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->willReturn(
            $this->installation(self::CHECKSUM_ONE, 3, 'complete'),
        );
        $database->expects(self::never())->method('update');

        self::assertFalse($this->ledger($database)->begin(
            'default',
            'site-content',
            'documentation',
            3,
            self::CHECKSUM_ONE,
        ));
    }

    /**
     * Prove an explicit version increment admits new manifest bytes and schedules reconciliation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHigherVersionMayIntroduceANewChecksum(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->willReturn(
            $this->installation(self::CHECKSUM_ONE, 3, 'complete'),
        );
        $database->expects(self::once())->method('update')->willReturn(1);

        self::assertTrue($this->ledger($database)->begin(
            'default',
            'site-content',
            'documentation',
            4,
            self::CHECKSUM_TWO,
        ));
    }

    /**
     * Construct a ledger around the supplied observable database seam and a deterministic clock.
     *
     * @param   Connection  $database  Mock connection supplying the selector checkpoint.
     *
     * @return  DoctrineDemoProfileLedger  Ledger under test.
     *
     * @since   2.0.0
     */
    private function ledger(Connection $database): DoctrineDemoProfileLedger
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-11T12:00:00+00:00'));

        return new DoctrineDemoProfileLedger(
            $database,
            new TableNames($database, 'kumwe_'),
            $clock,
        );
    }

    /**
     * Create a connection mock whose identifier compiler behaves like an unquoted test platform.
     *
     * @return  Connection  Mock database connection.
     *
     * @since   2.0.0
     */
    private function database(): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );

        return $database;
    }

    /**
     * Build the persisted selector shape returned by DBAL for one established profile.
     *
     * @param   string  $checksum  Canonical checksum already persisted.
     * @param   int     $version   Persisted monotonic manifest version.
     * @param   string  $status    Persisted reconciliation status.
     *
     * @return  array{
     *     selected_profile: string,
     *     manifest_version: int,
     *     manifest_checksum: string,
     *     status: string
     * }  Selector checkpoint returned to the ledger.
     *
     * @since   2.0.0
     */
    private function installation(string $checksum, int $version, string $status): array
    {
        return [
            'selected_profile' => 'documentation',
            'manifest_version' => $version,
            'manifest_checksum' => $checksum,
            'status' => $status,
        ];
    }
}
