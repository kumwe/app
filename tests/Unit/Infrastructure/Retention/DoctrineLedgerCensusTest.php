<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Retention;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\TableNotFoundException;
use InvalidArgumentException;
use Kumwe\App\Application\Retention\LedgerCount;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Infrastructure\Retention\DoctrineLedgerCensus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the census answer on an engine without a statement timeout, and that only a timeout is absorbed.
 *
 * The census may report "timed out" instead of a count, but only for the engine's own cancellation. Any
 * other driver failure — here a store whose table does not exist — is a fault the operator must see, not
 * a slow count to shrug off, so it propagates unchanged. On an engine with no statement-timeout syntax the
 * count runs plainly and is still exact.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineLedgerCensus::class)]
#[CoversClass(LedgerCount::class)]
final class DoctrineLedgerCensusTest extends TestCase
{
    /**
     * Without a timeout statement the count still runs exactly and declares its cost class.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEngineWithoutATimeoutStatementStillCountsExactly(): void
    {
        $database = self::database();
        $database->executeStatement('CREATE TABLE kumwe_jobs (id TEXT)');
        foreach (['a', 'b', 'c'] as $id) {
            $database->insert('kumwe_jobs', ['id' => $id]);
        }

        $count = (new DoctrineLedgerCensus($database, new TableNames($database, 'kumwe_')))
            ->count(RetentionStore::JobHistory, 1_000);

        self::assertSame(RetentionStore::JobHistory, $count->store);
        self::assertSame(3, $count->rows);
        self::assertFalse($count->timedOut);
        self::assertSame(1_000, $count->timeoutMilliseconds);
        self::assertGreaterThanOrEqual(0.0, $count->elapsedMilliseconds);
        self::assertSame('table_scan', $count->costClass);
    }

    /**
     * A failure that is not the engine's timeout propagates instead of being reported as timed out.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFailureOtherThanATimeoutPropagates(): void
    {
        $database = self::database();

        $this->expectException(TableNotFoundException::class);

        (new DoctrineLedgerCensus($database, new TableNames($database, 'kumwe_')))
            ->count(RetentionStore::ProcessHistory, 1_000);
    }

    /**
     * A timeout outside one millisecond to thirty seconds is refused before any statement runs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATimeoutOutsideItsBoundsIsRefused(): void
    {
        $database = self::database();
        $census = new DoctrineLedgerCensus($database, new TableNames($database, 'kumwe_'));
        foreach ([0, 30_001] as $timeout) {
            try {
                $census->count(RetentionStore::JobHistory, $timeout);
                self::fail(sprintf('A %d millisecond timeout must be refused.', $timeout));
            } catch (InvalidArgumentException $refusal) {
                self::assertSame(
                    'A ledger census timeout must be between 1 and 30000 milliseconds.',
                    $refusal->getMessage(),
                );
            }
        }
    }

    /**
     * Open an in-memory engine with no statement-timeout syntax.
     *
     * @return  Connection  Empty engine.
     *
     * @since   2.0.0
     */
    private static function database(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }
}
