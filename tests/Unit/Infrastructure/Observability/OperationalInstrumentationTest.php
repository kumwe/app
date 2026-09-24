<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Observability;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Kumwe\App\BusinessRecord\Application\DocumentCommitTimingRecorder;
use Kumwe\App\Infrastructure\Observability\InstrumentedTransactionManager;
use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\MetricDocumentCommitObserver;
use Kumwe\App\Tests\Support\RecordingMetricRecorder;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the low-cardinality P5-F instrumentation: transactions, lock conflicts and document commits.
 *
 * @since  2.0.0
 */
#[CoversClass(InstrumentedTransactionManager::class)]
#[CoversClass(MetricDocumentCommitObserver::class)]
#[CoversClass(DocumentCommitTimingRecorder::class)]
final class OperationalInstrumentationTest extends TestCase
{
    /**
     * Only outermost transactions are counted, and wrapped deadlocks and lock timeouts are classified.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOutermostTransactionsAreCountedAndLockConflictsClassified(): void
    {
        $metrics = new RecordingMetricRecorder();
        $manager = new InstrumentedTransactionManager(new ImmediateTransactionManager(), $metrics);
        $nested = static fn (): int => $manager->transactional(static fn (): int => 3);
        self::assertSame(3, $manager->transactional($nested));
        $manager->afterCommit(static fn (): null => null);
        $manager->afterRollback(static fn (): null => null);
        $driver = self::createStub(DriverException::class);
        foreach (
            [new DeadlockException($driver, null), new LockWaitTimeoutException($driver, null),
            new RuntimeException('other')] as $failure
        ) {
            try {
                $manager->transactional(static function () use ($failure): never {
                    throw new RuntimeException('wrapped', 0, $failure);
                });
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(1.0, $metrics->total(MetricCatalog::TRANSACTIONS, ['outcome' => 'committed']));
        self::assertSame(3.0, $metrics->total(MetricCatalog::TRANSACTIONS, ['outcome' => 'rolled_back']));
        self::assertSame(1.0, $metrics->total(MetricCatalog::TRANSACTION_FAILURES, ['class' => 'deadlock']));
        self::assertSame(1.0, $metrics->total(MetricCatalog::TRANSACTION_FAILURES, ['class' => 'lock_timeout']));
        self::assertCount(4, $metrics->observations);
    }

    /**
     * A committed document is timed under its line class and its lines counted; an abandoned one is not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentCommitsAreClassifiedByLineCount(): void
    {
        $metrics = new RecordingMetricRecorder();
        $recorder = new DocumentCommitTimingRecorder(new MetricDocumentCommitObserver($metrics));
        $recorder->begin();
        $recorder->commit(40.0, 100);
        $recorder->begin();
        $recorder->commit(900.0, 1_000);
        $recorder->begin();
        $recorder->abandon();
        $recorder->commit(1.0, 5);
        self::assertSame(1_100.0, $metrics->total(MetricCatalog::DOCUMENT_LINES));
        self::assertSame(
            ['document_100_line_commit', 'document_1000_line_commit'],
            array_map(static fn (array $entry): string => $entry['labels']['operation_class'], $metrics->observations),
        );
        self::assertSame(0.04, $metrics->observations[0]['value']);
    }
}
