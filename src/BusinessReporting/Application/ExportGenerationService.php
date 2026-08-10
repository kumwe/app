<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Application;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\BusinessRecord\Application\Query\BusinessRecordQueryPurpose;
use Kumwe\CMS\BusinessReporting\Domain\ExportArtifact;
use Kumwe\CMS\BusinessReporting\Domain\ExportArtifactStatus;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Idempotent queue-side generation of one authorization-bound export artifact.
 *
 * @since  2.0.0
 */
final readonly class ExportGenerationService
{
    /**
     * Wire job execution to fresh actor authority, report execution, storage and metadata.
     *
     * @param  ExportArtifactRepository       $artifacts     Durable metadata ledger.
     * @param  ExportExecutionContextResolver $contexts      Fresh original-actor context resolver.
     * @param  ExportService                  $exports       Current binding and authorization verifier.
     * @param  ReportService                  $reports       Policy-aware report executor.
     * @param  ReportCsvEncoder               $csv           Deterministic safe CSV encoder.
     * @param  ExportArtifactStorage          $storage       Private immutable artifact store.
     * @param  TransactionManager             $transactions  Metadata/audit transaction owner.
     * @param  AuditRecorder                  $audit         Redacted audit sink.
     * @param  ClockInterface                 $clock         Trusted wall clock.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExportArtifactRepository $artifacts,
        private ExportExecutionContextResolver $contexts,
        private ExportService $exports,
        private ReportService $reports,
        private ReportCsvEncoder $csv,
        private ExportArtifactStorage $storage,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Generate or safely resume one artifact by id.
     *
     * @param   string            $artifactId    Canonical artifact UUID from the queue payload.
     * @param   ExecutionContext  $workerContext Narrow system context that claimed the job.
     *
     * @return  void
     *
     * @throws  ExportGenerationRejected  When authority, policy, report or expiry no longer matches.
     * @throws  Throwable                  On a transient execution, storage or persistence failure.
     *
     * @since   2.0.0
     */
    public function generate(string $artifactId, ExecutionContext $workerContext): void
    {
        $artifact = $this->artifacts->find($artifactId)
            ?? throw new ExportGenerationRejected('The export request is unavailable.');
        if ($artifact->status === ExportArtifactStatus::Completed
            || $artifact->status === ExportArtifactStatus::Failed
        ) {
            return;
        }
        try {
            $context = $this->contexts->resolve($artifact, $workerContext);
            $artifact = $this->exports->status($context, $artifact->id);
        } catch (Throwable $exception) {
            $this->reject($artifact, 'authorization_changed');
            throw new ExportGenerationRejected('The export authority or policy changed.', 0, $exception);
        }
        if ($artifact->status === ExportArtifactStatus::Queued) {
            $running = $artifact->start($this->clock->now());
            $this->transactions->transactional(function () use ($artifact, $running): void {
                $this->artifacts->save($running, $artifact->version);
                $this->audit($running, 'business.report.export.start', 'success', $running->startedAt);
            });
            $artifact = $running;
        }
        if ($artifact->status !== ExportArtifactStatus::Running) {
            return;
        }
        $staleKey = strtolower($artifact->id) . '.csv';
        $this->storage->delete($staleKey);
        try {
            $result = $this->reports->execute(new ReportExecutionRequest(
                $context,
                $artifact->reportIdentifier,
                $artifact->parameters,
                $artifact->organizationIdentifier,
                BusinessRecordQueryPurpose::Export,
            ));
            if (!hash_equals($artifact->definitionChecksum, $result->definitionChecksum)) {
                throw new ExportGenerationRejected('The report definition changed during export.');
            }
            $stored = $this->storage->store($artifact->id, $this->csv->encode($result));
            $completed = $artifact->complete(
                $this->clock->now(),
                $stored->key,
                $stored->size,
                $stored->checksum,
                count($result->rows),
                $result->queryDigest,
            );
            $this->transactions->transactional(function () use ($artifact, $completed): void {
                $this->artifacts->save($completed, $artifact->version);
                $this->audit(
                    $completed,
                    'business.report.export.complete',
                    'success',
                    $completed->completedAt,
                );
            });
        } catch (ReportRowLimitExceeded $exception) {
            $this->storage->delete($staleKey);
            $this->reject($artifact, 'row_limit');
            throw new ExportGenerationRejected('The export exceeds its configured row limit.', 0, $exception);
        } catch (ExportGenerationRejected $exception) {
            $this->storage->delete($staleKey);
            $this->reject($artifact, 'definition_changed');
            throw $exception;
        } catch (Throwable $exception) {
            $this->storage->delete($staleKey);
            $this->audit($artifact, 'business.report.export.attempt', 'failure', $this->clock->now());
            throw $exception;
        }
    }

    /** @since 2.0.0 */
    private function reject(ExportArtifact $artifact, string $code): void
    {
        $current = $this->artifacts->find($artifact->id);
        if ($current === null || $current->status === ExportArtifactStatus::Completed
            || $current->status === ExportArtifactStatus::Failed
        ) {
            return;
        }
        $failed = $current->fail($this->clock->now(), $code);
        $this->transactions->transactional(function () use ($current, $failed): void {
            $this->artifacts->save($failed, $current->version);
            $this->audit($failed, 'business.report.export.generate', 'failure', $failed->completedAt);
        });
    }

    /** @since 2.0.0 */
    private function audit(
        ExportArtifact $artifact,
        string $action,
        string $outcome,
        ?DateTimeImmutable $now,
    ): void {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $now ?? $this->clock->now(),
            $artifact->actorId,
            $action,
            'report_export',
            $artifact->id,
            $outcome,
            [
                'report_identifier' => $artifact->reportIdentifier,
                'definition_checksum' => $artifact->definitionChecksum,
                'parameter_digest' => $artifact->parameterDigest,
                'status' => $artifact->status->value,
                'row_count' => $artifact->rowCount,
                'artifact_checksum' => $artifact->checksum,
                'failure_code' => $artifact->failureCode,
            ],
        ));
    }
}
