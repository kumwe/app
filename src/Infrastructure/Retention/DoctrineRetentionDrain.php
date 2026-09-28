<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Retention;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\Application\Retention\RetentionBudget;
use Kumwe\App\Application\Retention\RetentionCatalogue;
use Kumwe\App\Application\Retention\RetentionDrain;
use Kumwe\App\Application\Retention\RetentionDrainResult;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\App\Audit\Application\AuditRetentionService;
use Kumwe\App\BusinessRecord\Application\BusinessRecordIdempotencyPurger;
use Kumwe\App\BusinessReporting\Application\ExportArtifactStorage;
use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\MetricRecorder;
use Kumwe\App\Infrastructure\Observability\NullMetricRecorder;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Automation\QueueRuntimePolicyCatalog;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Idempotency\IdempotencyPurger;
use Kumwe\Integration\OutboxStore;
use Kumwe\Transaction\Contract\TransactionManager;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Time-budgeted, adaptively batched drain of every declared hot store on the authoritative database.
 *
 * One loop serves every store: it starts at the budget's initial batch, runs one short transaction per
 * batch, resizes the next batch from how long the last one took, and stops when a batch comes back
 * short, when the time budget is spent or when the batch cap is hit. What differs per store is only the
 * batch statement, and each of those repeats the same rule the store's own purge already follows —
 * candidates are read under `SKIP LOCKED` so a live row is never waited on, and a row that must survive
 * for a consumer that has not caught up is never a candidate at all. The idempotency ledgers delegate
 * to the purgers that already own their predicates; the outbox delegates to its store; audit delegates
 * to the retention service that archives, verifies and anchors before it deletes.
 *
 * @since  2.0.0
 */
final readonly class DoctrineRetentionDrain implements RetentionDrain
{
    /**
     * Age after which a settled receipt's evidence is compacted, well before its tombstone is removed.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int RECEIPT_COMPACTION_SECONDS = 86_400;

    /**
     * Bind the drain to the stores it removes from and the purgers it delegates to.
     *
     * @param  Connection                       $database        Authoritative connection.
     * @param  TableNames                       $tables          Prefixed physical table names.
     * @param  TransactionManager               $transactions    Short transaction per batch.
     * @param  ClockInterface                   $clock           Instant every cutoff is measured from.
     * @param  RetentionCatalogue               $catalogue       Declared windows and budgets.
     * @param  BusinessRecordIdempotencyPurger  $businessClaims  Owner of the business idempotency predicate.
     * @param  IdempotencyPurger                $deliveryClaims  Owner of the delivery idempotency predicate.
     * @param  OutboxStore                      $outbox          Owner of the outbox purge.
     * @param  AuditRetentionService            $audit           Archives, verifies and anchors before pruning.
     * @param  ExportArtifactStorage            $exports         Deletes an artifact's bytes with its row.
     * @param  QueueRuntimePolicyCatalog        $queues          Names the contributed queues this drain leaves
     *         to their signed retention.
     * @param  RetentionRunLedger               $runs            Records each run for the observer.
     * @param  MetricRecorder                   $metrics         Counts rows drained per store.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private RetentionCatalogue $catalogue,
        private BusinessRecordIdempotencyPurger $businessClaims,
        private IdempotencyPurger $deliveryClaims,
        private OutboxStore $outbox,
        private AuditRetentionService $audit,
        private ExportArtifactStorage $exports,
        private QueueRuntimePolicyCatalog $queues,
        private RetentionRunLedger $runs,
        private MetricRecorder $metrics = new NullMetricRecorder(),
    ) {
    }

    /**
     * Drain one store within a budget.
     *
     * @param   RetentionStore    $store    Store to drain.
     * @param   RetentionBudget   $budget   Time, batch and lock bounds for this run.
     * @param   ExecutionContext  $context  Actor the run is authorized and audited under.
     *
     * @return  RetentionDrainResult  Rows removed, batches issued and why the run stopped.
     *
     * @throws  InvalidArgumentException  When the store is drained by a site-scoped job of its own.
     *
     * @since   2.0.0
     */
    public function drain(
        RetentionStore $store,
        RetentionBudget $budget,
        ExecutionContext $context,
    ): RetentionDrainResult {
        $policy = $this->catalogue->policy($store);
        if ($store === RetentionStore::Sessions) {
            throw new InvalidArgumentException('Sessions are drained per site by system.sessions.purge.');
        }
        if (!$policy->drainable()) {
            return $this->finish(new RetentionDrainResult($store, 0, 0, 0.0, $budget->initialBatch(), true, false));
        }
        $now = $this->clock->now();
        $cutoff = $now->sub(new DateInterval(sprintf('PT%dS', $policy->minimumRetentionSeconds)));
        $started = hrtime(true);
        $deadline = $started + $budget->timeBudgetSeconds * 1_000_000_000;
        $batch = $budget->initialBatch();
        $rows = 0;
        $batches = 0;
        $cleared = false;
        $exhausted = false;
        while (true) {
            if ($batches >= $budget->maximumBatches || hrtime(true) >= $deadline) {
                $exhausted = true;
                break;
            }
            $batchStarted = hrtime(true);
            $affected = $this->batch($store, $batch, $now, $cutoff, $context);
            $batchMilliseconds = (hrtime(true) - $batchStarted) / 1_000_000;
            $batches++;
            $rows += $affected;
            if ($affected < $batch) {
                $cleared = true;
                break;
            }
            $batch = $budget->nextBatch($batch, $batchMilliseconds);
        }

        return $this->finish(new RetentionDrainResult(
            $store,
            $rows,
            $batches,
            (hrtime(true) - $started) / 1_000_000_000,
            $batch,
            $cleared,
            $exhausted,
        ));
    }

    /**
     * Record a finished run and publish its counter.
     *
     * @param   RetentionDrainResult  $result  Outcome of the run.
     *
     * @return  RetentionDrainResult  The same result.
     *
     * @since   2.0.0
     */
    private function finish(RetentionDrainResult $result): RetentionDrainResult
    {
        $this->runs->record($result, $this->clock->now());
        if ($result->rowsDrained > 0) {
            $this->metrics->increment(
                MetricCatalog::RETENTION_DRAINED,
                ['store' => $result->store->value],
                (float) $result->rowsDrained,
            );
        }

        return $result;
    }

    /**
     * Run one batch for a store and report how many rows it affected.
     *
     * @param   RetentionStore     $store    Store being drained.
     * @param   int                $limit    Rows this batch may remove.
     * @param   DateTimeImmutable  $now      Instant of the run.
     * @param   DateTimeImmutable  $cutoff   Instant a row's settlement must predate to be eligible.
     * @param   ExecutionContext   $context  Actor, for the audited stores.
     *
     * @return  int  Rows removed or compacted; fewer than the limit means nothing more was eligible.
     *
     * @since   2.0.0
     */
    private function batch(
        RetentionStore $store,
        int $limit,
        DateTimeImmutable $now,
        DateTimeImmutable $cutoff,
        ExecutionContext $context,
    ): int {
        return match ($store) {
            RetentionStore::BusinessIdempotency => $this->businessClaims->purge($limit),
            RetentionStore::DeliveryIdempotency => $this->deliveryClaims->purgeExpired($limit),
            RetentionStore::OutboxSourceEvents => $this->outbox->purgeExpired($now, $limit),
            RetentionStore::SequencedJournal => $this->journalBatch($limit, $cutoff),
            RetentionStore::InboxReceipts => $this->receiptBatch($limit, $now, $cutoff),
            RetentionStore::JobHistory => $this->jobBatch($limit, $cutoff),
            RetentionStore::ProcessHistory => $this->processBatch($limit, $cutoff),
            RetentionStore::ExportArtifacts => $this->exportBatch($limit, $now),
            RetentionStore::Audit => $this->auditBatch($context),
            RetentionStore::Revisions, RetentionStore::Sessions => 0,
        };
    }

    /**
     * Remove journal rows below every live projection checkpoint whose outbox row is already gone.
     *
     * @param   int                $limit   Rows this batch may remove.
     * @param   DateTimeImmutable  $cutoff  Instant a row's recording must predate.
     *
     * @return  int  Rows removed.
     *
     * @since   2.0.0
     */
    private function journalBatch(int $limit, DateTimeImmutable $cutoff): int
    {
        return $this->transactions->transactional(function () use ($limit, $cutoff): int {
            $floor = $this->database->fetchOne(sprintf(
                "SELECT MIN(last_sequence) FROM %s WHERE status IN ('active', 'building')",
                $this->tables->quoted('business_projection_generations'),
            ));
            $bounded = is_int($floor) || (is_string($floor) && is_numeric($floor));
            $sequences = $this->database->fetchFirstColumn(sprintf(
                'SELECT j.source_sequence FROM %s j WHERE j.recorded_at <= ?%s '
                . 'AND NOT EXISTS (SELECT 1 FROM %s o WHERE o.event_id = j.event_id) '
                . 'ORDER BY j.source_sequence LIMIT %d%s',
                $this->tables->quoted('business_projection_source_events'),
                $bounded ? ' AND j.source_sequence <= ?' : '',
                $this->tables->quoted('integration_outbox'),
                $limit,
                $this->lock(),
            ), $bounded ? [$cutoff, (int) $floor] : [$cutoff], $bounded
                ? [Types::DATETIME_IMMUTABLE, Types::BIGINT]
                : [Types::DATETIME_IMMUTABLE]);
            if ($sequences === []) {
                return 0;
            }

            return (int) $this->database->executeStatement(sprintf(
                'DELETE FROM %s WHERE source_sequence IN (?)',
                $this->tables->quoted('business_projection_source_events'),
            ), [$sequences], [ArrayParameterType::INTEGER]);
        });
    }

    /**
     * Compact settled receipts a day old and remove tombstones whose outbox row is already gone.
     *
     * @param   int                $limit   Rows this batch may touch, split between the two phases.
     * @param   DateTimeImmutable  $now     Instant of the run.
     * @param   DateTimeImmutable  $cutoff  Instant a tombstone's settlement must predate to be removed.
     *
     * @return  int  Rows compacted plus rows removed.
     *
     * @since   2.0.0
     */
    private function receiptBatch(int $limit, DateTimeImmutable $now, DateTimeImmutable $cutoff): int
    {
        $compactBefore = $now->sub(new DateInterval(sprintf('PT%dS', self::RECEIPT_COMPACTION_SECONDS)));

        return $this->transactions->transactional(function () use ($limit, $now, $cutoff, $compactBefore): int {
            $inbox = $this->tables->quoted('integration_inbox');
            $keys = $this->database->fetchAllAssociative(sprintf(
                "SELECT consumer_id, event_id FROM %s WHERE status IN ('completed', 'poison', 'unavailable') "
                . 'AND evidence_compacted_at IS NULL AND updated_at <= ? '
                . 'ORDER BY updated_at, consumer_id, event_id LIMIT %d%s',
                $inbox,
                $limit,
                $this->lock(),
            ), [$compactBefore], [Types::DATETIME_IMMUTABLE]);
            $compacted = 0;
            foreach ($keys as $key) {
                $compacted += (int) $this->database->executeStatement(sprintf(
                    "UPDATE %s SET envelope = '{}', lease_owner = NULL, lease_token = NULL, lease_acquired_at = NULL, "
                    . 'lease_expires_at = NULL, runtime_generation = NULL, failure_classification = NULL, '
                    . 'exception_type = NULL, error_message = NULL, evidence_compacted_at = ? '
                    . 'WHERE consumer_id = ? AND event_id = ?',
                    $inbox,
                ), [$now, $key['consumer_id'], $key['event_id']], [
                    Types::DATETIME_IMMUTABLE, Types::STRING, Types::STRING,
                ]);
            }
            $remaining = $limit - $compacted;
            if ($remaining < 1) {
                return $compacted;
            }
            $tombstones = $this->database->fetchAllAssociative(sprintf(
                "SELECT i.consumer_id, i.event_id FROM %s i WHERE i.status IN ('completed', 'poison', 'unavailable') "
                . 'AND i.updated_at <= ? AND NOT EXISTS (SELECT 1 FROM %s o WHERE o.event_id = i.event_id) '
                . 'ORDER BY i.updated_at, i.consumer_id, i.event_id LIMIT %d%s',
                $inbox,
                $this->tables->quoted('integration_outbox'),
                $remaining,
                $this->lock(),
            ), [$cutoff], [Types::DATETIME_IMMUTABLE]);
            $removed = 0;
            foreach ($tombstones as $key) {
                $removed += (int) $this->database->executeStatement(
                    sprintf('DELETE FROM %s WHERE consumer_id = ? AND event_id = ?', $inbox),
                    [$key['consumer_id'], $key['event_id']],
                    [Types::STRING, Types::STRING],
                );
            }

            return $compacted + $removed;
        });
    }

    /**
     * Remove settled jobs on the core queues, together with their dead-letter and ownership rows.
     *
     * @param   int                $limit   Rows this batch may remove.
     * @param   DateTimeImmutable  $cutoff  Instant a job's settlement must predate.
     *
     * @return  int  Jobs removed.
     *
     * @since   2.0.0
     */
    private function jobBatch(int $limit, DateTimeImmutable $cutoff): int
    {
        $contributed = [];
        foreach ($this->queues->policies() as $policy) {
            $contributed[] = $policy->queue;
        }

        return $this->transactions->transactional(function () use ($limit, $cutoff, $contributed): int {
            $ids = $this->database->fetchFirstColumn(sprintf(
                "SELECT id FROM %s WHERE ((status = 'completed' AND completed_at <= ?) "
                . "OR (status IN ('dead', 'canceled') AND updated_at <= ?))%s "
                . 'ORDER BY updated_at, id LIMIT %d%s',
                $this->tables->quoted('jobs'),
                $contributed === [] ? '' : ' AND queue NOT IN (?)',
                $limit,
                $this->lock(),
            ), array_merge([$cutoff, $cutoff], $contributed === [] ? [] : [$contributed]), array_merge(
                [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE],
                $contributed === [] ? [] : [ArrayParameterType::STRING],
            ));
            if ($ids === []) {
                return 0;
            }
            $this->database->executeStatement(sprintf(
                'DELETE FROM %s WHERE job_id IN (?)',
                $this->tables->quoted('failed_jobs'),
            ), [$ids], [ArrayParameterType::STRING]);
            $this->database->executeStatement(sprintf(
                "DELETE FROM %s WHERE resource_type = 'job' AND resource_id IN (?)",
                $this->tables->quoted('resource_site_ownership'),
            ), [$ids], [ArrayParameterType::STRING]);

            return (int) $this->database->executeStatement(
                sprintf('DELETE FROM %s WHERE id IN (?)', $this->tables->quoted('jobs')),
                [$ids],
                [ArrayParameterType::STRING],
            );
        });
    }

    /**
     * Remove settled process work items.
     *
     * @param   int                $limit   Rows this batch may remove.
     * @param   DateTimeImmutable  $cutoff  Instant a work item's settlement must predate.
     *
     * @return  int  Work items removed.
     *
     * @since   2.0.0
     */
    private function processBatch(int $limit, DateTimeImmutable $cutoff): int
    {
        return $this->transactions->transactional(function () use ($limit, $cutoff): int {
            $ids = $this->database->fetchFirstColumn(sprintf(
                "SELECT work_id FROM %s WHERE status IN ('completed', 'dead', 'canceled') AND updated_at <= ? "
                . 'ORDER BY updated_at, work_id LIMIT %d%s',
                $this->tables->quoted('business_process_work'),
                $limit,
                $this->lock(),
            ), [$cutoff], [Types::DATETIME_IMMUTABLE]);
            if ($ids === []) {
                return 0;
            }

            return (int) $this->database->executeStatement(
                sprintf('DELETE FROM %s WHERE work_id IN (?)', $this->tables->quoted('business_process_work')),
                [$ids],
                [ArrayParameterType::STRING],
            );
        });
    }

    /**
     * Remove expired export artifacts, deleting each one's stored bytes before its row.
     *
     * @param   int                $limit  Rows this batch may remove.
     * @param   DateTimeImmutable  $now    Instant an artifact's expiry must have passed.
     *
     * @return  int  Artifacts removed.
     *
     * @since   2.0.0
     */
    private function exportBatch(int $limit, DateTimeImmutable $now): int
    {
        return $this->transactions->transactional(function () use ($limit, $now): int {
            $rows = $this->database->fetchAllAssociative(sprintf(
                'SELECT artifact_id, document FROM %s WHERE expires_at <= ? ORDER BY expires_at, artifact_id '
                . 'LIMIT %d%s',
                $this->tables->quoted('business_report_export_artifacts'),
                $limit,
                $this->lock(),
            ), [$now], [Types::DATETIME_IMMUTABLE]);
            $removed = 0;
            foreach ($rows as $row) {
                $document = $row['document'] ?? null;
                $decoded = is_string($document) ? json_decode($document, true) : $document;
                $key = is_array($decoded) ? ($decoded['storage_key'] ?? null) : null;
                if (is_string($key) && $key !== '') {
                    $this->exports->delete($key);
                }
                $removed += (int) $this->database->executeStatement(
                    sprintf(
                        'DELETE FROM %s WHERE artifact_id = ?',
                        $this->tables->quoted('business_report_export_artifacts'),
                    ),
                    [$row['artifact_id']],
                );
            }

            return $removed;
        });
    }

    /**
     * Archive, verify, anchor and prune one aged audit range under the configured window.
     *
     * @param   ExecutionContext  $context  Actor the pass is authorized and audited under.
     *
     * @return  int  One when a range was pruned, zero when nothing was eligible or no window is configured.
     *
     * @since   2.0.0
     */
    private function auditBatch(ExecutionContext $context): int
    {
        $days = $this->auditRetentionDays();
        if ($days < 1) {
            return 0;
        }

        return $this->audit->prune($context, $days)->prunedCount > 0 ? 1 : 0;
    }

    /**
     * Read the configured audit retention window from its schedule.
     *
     * @return  int  Retention days, or zero when the schedule is absent, disabled or unconfigured.
     *
     * @throws  RuntimeException  When the schedule payload is not decodable.
     *
     * @since   2.0.0
     */
    private function auditRetentionDays(): int
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT payload, enabled FROM %s WHERE job_type = ?',
            $this->tables->quoted('schedules'),
        ), ['audit.retention.enforce']);
        if ($row === false || !in_array($row['enabled'] ?? null, [true, 1, '1'], true)) {
            return 0;
        }
        $payload = $row['payload'] ?? null;
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;
        if ($decoded !== null && !is_array($decoded)) {
            throw new RuntimeException('The audit retention schedule payload is not decodable.');
        }
        $days = is_array($decoded) ? ($decoded['retention_days'] ?? 0) : 0;

        return is_int($days) && $days > 0 ? $days : 0;
    }

    /**
     * Skip-locked candidate reads on the engines that support them.
     *
     * @return  string  Locking suffix.
     *
     * @since   2.0.0
     */
    private function lock(): string
    {
        $platform = $this->database->getDatabasePlatform();

        return $platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform
            ? ' FOR UPDATE SKIP LOCKED'
            : '';
    }
}
