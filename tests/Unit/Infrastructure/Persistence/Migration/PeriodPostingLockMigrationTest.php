<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationPortabilityMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\PeriodPostingLockMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the migration's ledger identity, which is part of the released contract once a site has run it.
 *
 * @since  2.0.0
 */
#[CoversClass(PeriodPostingLockMigration::class)]
final class PeriodPostingLockMigrationTest extends TestCase
{
    /**
     * The identity is a well-formed ledger key that sorts after every migration this build ships.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheIdentitySortsAfterTheMigrationsItFollows(): void
    {
        self::assertSame('20260821010000_period_posting_lock', PeriodPostingLockMigration::ID);
        self::assertMatchesRegularExpression(
            '/^[0-9]{14}_[a-z0-9_]+$/D',
            PeriodPostingLockMigration::ID,
        );
        self::assertGreaterThan(
            ConstraintNameIsolationPortabilityMigration::ID,
            PeriodPostingLockMigration::ID,
        );
    }

    /**
     * The checksum is derived from this build's bytes and has the shape the plan accepts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheChecksumBindsTheLedgerEntryToThisExactImplementation(): void
    {
        $migration = $this->migration();

        self::assertSame(PeriodPostingLockMigration::ID, $migration->id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $migration->checksum());
        self::assertSame($migration->checksum(), $this->migration()->checksum());
    }

    /**
     * Build the migration over a prefixed table map.
     *
     * @return  PeriodPostingLockMigration  Migration under test.
     *
     * @since   2.0.0
     */
    private function migration(): PeriodPostingLockMigration
    {
        $database = $this->createStub(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );

        return new PeriodPostingLockMigration(new TableNames($database, 'kumwe_'));
    }
}
