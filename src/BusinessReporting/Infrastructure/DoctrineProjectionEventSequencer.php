<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Infrastructure;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\MetricRecorder;
use Kumwe\App\Infrastructure\Observability\NullMetricRecorder;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Transaction\Contract\TransactionManager;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Assigns checkpoint order only after authoritative source transactions have committed (V2-SCL-002).
 *
 * The staging identity is allocation order, never a checkpoint. One short transaction claims the head
 * and a bounded committed source batch, copies it into a contiguous journal range, advances the head
 * and removes staging rows. Rollback or connection loss restores all four effects together. An earlier
 * uncommitted source skipped today receives a later journal sequence after it commits. Aggregate writers
 * must hold their record/version fence through staging; their later versions cannot commit ahead of them.
 *
 * This is host SQL persistence. Integration owns event contracts and Reporting owns builder semantics;
 * neither package owns database sequencing, transaction isolation or recovery.
 *
 * @since  2.0.0
 */
final readonly class DoctrineProjectionEventSequencer
{
    /**
     * Conservative statement payload budget including escaped envelopes and per-row metadata.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAXIMUM_INSERT_BYTES = 1_048_576;

    /**
     * Bind sequencing to the same database as authoritative staging, outside its transaction.
     *
     * @param  Connection          $database      Connection used for a fresh, short transaction.
     * @param  TableNames          $tables        Installation-local physical table names.
     * @param  TransactionManager  $transactions  Atomic range publication and staging removal.
     * @param  MetricRecorder      $metrics       Counts sequenced events after each committed range.
     * @param  LoggerInterface     $logger        Receives one line per committed range and per failed attempt.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private MetricRecorder $metrics = new NullMetricRecorder(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Publish at most one bounded committed batch without waiting for another sequencer or writer.
     *
     * The head is acquired before reading staging, so concurrent sequencers cannot publish ranges out
     * of order. SKIP LOCKED on staging excludes still-uncommitted inserts. No claim or allocation survives
     * independently of the journal transaction, so recovery needs neither a timeout nor gap repair.
     *
     * @param   int  $limit  Maximum source rows in this transaction, between 1 and 1000.
     *
     * @return  int  Rows sequenced; zero also means a concurrent sequencer owns the head.
     *
     * @throws  InvalidArgumentException  When the batch exceeds the bounded range.
     * @throws  LogicException  When called inside an authoritative or stale snapshot transaction.
     * @throws  RuntimeException  When durable head or row-count invariants fail.
     *
     * @since   2.0.0
     */
    public function sequence(int $limit = 500): int
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('A projection sequencing batch must contain between 1 and 1000 rows.');
        }
        if ($this->database->isTransactionActive()) {
            throw new LogicException('Projection sequencing requires its own committed-source transaction.');
        }

        $started = hrtime(true);
        try {
            $sequenced = $this->sequenceBatch($limit);
        } catch (Throwable $failure) {
            // Logged for the operator and rethrown unchanged: the caller owns the failure.
            $this->logger->warning('Projection source sequencing failed.', [
                'operation' => 'projection.sequence',
                'exception' => $failure,
            ]);
            throw $failure;
        }
        if ($sequenced > 0) {
            $this->metrics->increment(MetricCatalog::SEQUENCED_EVENTS, [], (float) $sequenced);
            $this->logger->info('Projection sources sequenced.', [
                'operation' => 'projection.sequence',
                'sequenced' => $sequenced,
                'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
            ]);
        }

        return $sequenced;
    }

    /**
     * Publish one bounded range in its own transaction and report how many rows it covered.
     *
     * @param   int  $limit  Maximum source rows in this transaction.
     *
     * @return  int  Rows sequenced; zero when nothing was staged or a concurrent sequencer owns the head.
     *
     * @throws  RuntimeException  When durable head or row-count invariants fail.
     *
     * @since   2.0.0
     */
    private function sequenceBatch(int $limit): int
    {
        return $this->transactions->transactional(function () use ($limit): int {
            $platform = $this->database->getDatabasePlatform();
            $lock = $platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform
                ? ' FOR UPDATE SKIP LOCKED'
                : '';
            $head = $this->database->fetchOne(sprintf(
                'SELECT last_sequence FROM %s WHERE singleton_id = 1%s',
                $this->tables->quoted('business_projection_event_head'),
                $lock,
            ));
            if ($head === false) {
                if (
                    $this->database->fetchOne(sprintf(
                        'SELECT singleton_id FROM %s WHERE singleton_id = 1',
                        $this->tables->quoted('business_projection_event_head'),
                    )) === false
                ) {
                    throw new RuntimeException('The projection source journal head is unavailable.');
                }
                return 0;
            }
            if (
                (!is_int($head) && (!is_string($head) || preg_match('/^[0-9]+$/D', $head) !== 1))
                || (int) $head < 0 || (int) $head > PHP_INT_MAX - $limit
            ) {
                throw new RuntimeException('The projection source journal head is invalid or exhausted.');
            }
            $rows = $this->database->fetchAllAssociative(sprintf(
                'SELECT event_id, event_type, schema_version, sensitivity, envelope, event_checksum, recorded_at '
                . 'FROM %s ORDER BY staging_sequence LIMIT ?%s',
                $this->tables->quoted('business_projection_event_staging'),
                $lock,
            ), [$limit], [Types::INTEGER]);
            if ($rows === []) {
                return 0;
            }
            $count = count($rows);
            $ids = [];
            $parameters = [];
            $types = [];
            $bytes = 0;
            $inserted = 0;
            foreach ($rows as $offset => $row) {
                $ids[] = $row['event_id'];
                // Copy database JSON text unchanged; drivers returning decoded JSON are normalized once.
                $envelope = is_string($row['envelope'])
                    ? $row['envelope']
                    : json_encode($row['envelope'], JSON_THROW_ON_ERROR);
                $rowBytes = 2 * strlen($envelope) + 4_096;
                if ($parameters !== [] && $bytes + $rowBytes > self::MAXIMUM_INSERT_BYTES) {
                    $inserted += $this->insertJournalRows($parameters, $types);
                    $parameters = [];
                    $types = [];
                    $bytes = 0;
                }
                $bytes += $rowBytes;
                array_push(
                    $parameters,
                    (int) $head + $offset + 1,
                    $row['event_id'],
                    $row['event_type'],
                    $row['schema_version'],
                    $row['sensitivity'],
                    $envelope,
                    $row['event_checksum'],
                    $row['recorded_at'],
                );
                array_push(
                    $types,
                    Types::BIGINT,
                    Types::GUID,
                    Types::STRING,
                    Types::INTEGER,
                    Types::STRING,
                    Types::STRING,
                    Types::STRING,
                    Types::STRING,
                );
            }
            $inserted += $this->insertJournalRows($parameters, $types);
            $updated = $this->database->executeStatement(sprintf(
                'UPDATE %s SET last_sequence = ? WHERE singleton_id = 1 AND last_sequence = ?',
                $this->tables->quoted('business_projection_event_head'),
            ), [(int) $head + $count, (int) $head], [Types::BIGINT, Types::BIGINT]);
            $deleted = $this->database->executeStatement(sprintf(
                'DELETE FROM %s WHERE event_id IN (?)',
                $this->tables->quoted('business_projection_event_staging'),
            ), [$ids], [ArrayParameterType::STRING]);
            if ($inserted !== $count || (int) $updated !== 1 || (int) $deleted !== $count) {
                throw new RuntimeException('The projection source batch lost its sequencing fence.');
            }

            return $count;
        });
    }

    /**
     * Insert one byte-budgeted statement within the same atomic journal-range transaction.
     *
     * Package event envelopes already bound individual payloads. Splitting statements also avoids
     * multiplying that bound by 1000 into a packet larger than ordinary server configurations accept.
     *
     * @param   list<mixed>   $parameters  Eight ordered values per source row.
     * @param   list<string>  $types       Matching DBAL parameter types.
     *
     * @return  int  Rows inserted by this statement, checked against the full batch before commit.
     *
     * @since   2.0.0
     */
    private function insertJournalRows(array $parameters, array $types): int
    {
        return (int) $this->database->executeStatement(sprintf(
            'INSERT INTO %s (source_sequence, event_id, event_type, schema_version, sensitivity, '
            . 'envelope, event_checksum, recorded_at) VALUES %s',
            $this->tables->quoted('business_projection_source_events'),
            implode(', ', array_fill(0, intdiv(count($parameters), 8), '(?, ?, ?, ?, ?, ?, ?, ?)')),
        ), $parameters, $types);
    }
}
