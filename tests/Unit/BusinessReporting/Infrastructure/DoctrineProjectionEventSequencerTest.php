<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessReporting\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use Kumwe\App\BusinessReporting\Infrastructure\DoctrineProjectionEventSequencer;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins that the projection sequencer refuses a journal head it cannot trust instead of reporting idleness.
 *
 * A zero return means "nothing to publish, or another sequencer owns the head", so a missing or corrupt head
 * row must never collapse into that answer: replay would silently stop while the staging area kept growing.
 * The head and staging tables here carry only the columns the refusal path reads.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineProjectionEventSequencer::class)]
final class DoctrineProjectionEventSequencerTest extends TestCase
{
    /**
     * A journal whose head row is gone is refused, not treated as a head another sequencer holds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMissingJournalHeadIsRefusedRatherThanReportedAsIdle(): void
    {
        $database = self::database(null);

        self::assertSame(
            'The projection source journal head is unavailable.',
            self::refusal(fn () => self::sequencer($database)->sequence(10)),
        );
        self::assertFalse($database->isTransactionActive(), 'The refused pass leaves no transaction open.');
    }

    /**
     * A negative, non-numeric or exhausted head is refused before any staged row is read or sequenced.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testANegativeMalformedOrExhaustedHeadIsRefusedBeforeAnyRowIsSequenced(): void
    {
        foreach (['-1', 'ten', (string) (PHP_INT_MAX - 5)] as $head) {
            $database = self::database($head);
            $database->insert('kumwe_business_projection_event_staging', ['event_id' => 'staged']);

            self::assertSame(
                'The projection source journal head is invalid or exhausted.',
                self::refusal(fn () => self::sequencer($database)->sequence(10)),
                sprintf('Head %s must be refused.', $head),
            );
            self::assertSame(
                1,
                (int) $database->fetchOne('SELECT COUNT(*) FROM kumwe_business_projection_event_staging'),
                'The staged row is still waiting.',
            );
        }
    }

    /**
     * A current head with an empty staging area publishes nothing and says so.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEmptyStagingAreaSequencesNothing(): void
    {
        $database = self::database('41');

        self::assertSame(0, self::sequencer($database)->sequence(10));
        self::assertSame(
            41,
            (int) $database->fetchOne('SELECT last_sequence FROM kumwe_business_projection_event_head'),
        );
    }

    /**
     * An unbounded batch, or a call inside a caller's transaction, is refused before the head is read.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheBatchBoundAndOwnTransactionAreRequired(): void
    {
        $database = self::database(null);
        foreach ([0, 1_001] as $limit) {
            try {
                self::sequencer($database)->sequence($limit);
                self::fail(sprintf('A batch of %d rows must be refused.', $limit));
            } catch (InvalidArgumentException $refusal) {
                self::assertSame(
                    'A projection sequencing batch must contain between 1 and 1000 rows.',
                    $refusal->getMessage(),
                );
            }
        }
        $database->beginTransaction();
        try {
            self::sequencer($database)->sequence(10);
            self::fail('Sequencing inside a caller transaction must be refused.');
        } catch (LogicException $refusal) {
            self::assertSame(
                'Projection sequencing requires its own committed-source transaction.',
                $refusal->getMessage(),
            );
        } finally {
            $database->rollBack();
        }
    }

    /**
     * Run a sequencing pass that must be refused and return the refusal message.
     *
     * @param   callable(): int  $pass  Sequencing pass.
     *
     * @return  string  Message of the runtime refusal.
     *
     * @since   2.0.0
     */
    private static function refusal(callable $pass): string
    {
        try {
            $pass();
        } catch (RuntimeException $refusal) {
            return $refusal->getMessage();
        }
        self::fail('The sequencing pass must be refused.');
    }

    /**
     * Build the sequencer under test on the given connection.
     *
     * @param   Connection  $database  Connection holding the head and staging tables.
     *
     * @return  DoctrineProjectionEventSequencer  Sequencer under test.
     *
     * @since   2.0.0
     */
    private static function sequencer(Connection $database): DoctrineProjectionEventSequencer
    {
        return new DoctrineProjectionEventSequencer(
            $database,
            new TableNames($database, 'kumwe_'),
            new DoctrineTransactionManager($database),
        );
    }

    /**
     * Open an in-memory journal with the given head value, or no head row at all.
     *
     * @param   ?string  $head  Stored `last_sequence` text, or null for a missing head row.
     *
     * @return  Connection  Connection holding the head and staging tables.
     *
     * @since   2.0.0
     */
    private static function database(?string $head): Connection
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $database->executeStatement(
            'CREATE TABLE kumwe_business_projection_event_head (singleton_id INTEGER PRIMARY KEY, last_sequence TEXT)',
        );
        $database->executeStatement(
            'CREATE TABLE kumwe_business_projection_event_staging (staging_sequence INTEGER PRIMARY KEY, '
            . 'event_id TEXT, event_type TEXT, schema_version INTEGER, sensitivity TEXT, envelope TEXT, '
            . 'event_checksum TEXT, recorded_at TEXT)',
        );
        if ($head !== null) {
            $database->insert('kumwe_business_projection_event_head', [
                'singleton_id' => 1,
                'last_sequence' => $head,
            ]);
        }

        return $database;
    }
}
