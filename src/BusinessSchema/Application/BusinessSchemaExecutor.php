<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use DateInterval;
use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\CMS\BusinessSchema\Domain\SchemaOperation;
use Kumwe\CMS\BusinessSchema\Domain\SchemaOperationKind;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlan;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\CMS\BusinessSchema\Domain\SchemaStepStatus;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/** Applies only an approved immutable plan, under the global schema lock and durable fencing journal. */
final readonly class BusinessSchemaExecutor
{
    private const CHUNK_SIZE = 250;

    private const EMPTY_SCHEMA_CHECKSUM = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private const RECOVERY_MAX_AGE = 'P7D';

    public function __construct(
        private BusinessDefinitionRepository $definitions,
        private DefinitionPhysicalSchemaCompiler $compiler,
        private BusinessSchemaPlanRepository $plans,
        private BusinessSchemaInstallationRepository $installations,
        private BusinessSchemaRecoveryEvidenceRepository $evidence,
        private BusinessSchemaExecutionLock $lock,
        private BusinessSchemaExecutionStateGuard $executionState,
        private PhysicalSchemaGateway $physicalSchema,
        private BusinessSchemaRecordRepinGateway $recordRepins,
        private BusinessSchemaEnvironment $environment,
        private AuthorizationGateway $authorization,
        private AuditRecorder $audit,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function execute(ExecutionContext $context, string $planId): SchemaExecutionOutcome
    {
        $this->authorize($context, 'business.schema.execute');
        $plan = $this->requiredPlan($context, $planId);
        if ($plan->status !== SchemaPlanStatus::Approved) {
            throw new BusinessSchemaConflict('Only an approved schema plan can execute.');
        }

        return $this->run($context, $plan, false, false);
    }

    public function recover(ExecutionContext $context, string $planId): SchemaExecutionOutcome
    {
        $this->authorize($context, 'business.schema.recover');
        $plan = $this->requiredPlan($context, $planId);
        if (
            !in_array(
                $plan->status,
                [SchemaPlanStatus::Executing, SchemaPlanStatus::Failed, SchemaPlanStatus::RecoveryRequired],
                true,
            )
        ) {
            throw new BusinessSchemaConflict('Only an interrupted schema plan can be recovered.');
        }

        return $this->run($context, $plan, true, true);
    }

    /** Resume only the intentional foreign-key pause of an approved initial graph under execute authority. */
    public function resumeGraphBootstrap(ExecutionContext $context, string $planId): SchemaExecutionOutcome
    {
        $this->authorize($context, 'business.schema.execute');
        $plan = $this->requiredPlan($context, $planId);
        if (!$this->isGraphBootstrapPause($context, $plan)) {
            throw new BusinessSchemaConflict('Only a verified initial graph pause can resume during execution.');
        }

        return $this->run($context, $plan, true, false);
    }

    /** Verify that an initial plan paused only after durable graph-bootstrap postconditions. */
    public function isGraphBootstrapPause(ExecutionContext $context, SchemaPlan $plan): bool
    {
        if (
            $plan->siteIdentifier !== $context->site()->identifier()
            || $plan->fromSchemaChecksum !== null
            || $plan->status !== SchemaPlanStatus::RecoveryRequired
        ) {
            return false;
        }
        try {
            [$target, $purge] = $this->target($context, $plan, true);
        } catch (Throwable) {
            return false;
        }
        if ($purge) {
            return false;
        }
        $operations = $plan->operations();
        $steps = $this->plans->steps($plan->id);
        if (count($steps) !== count($operations)) {
            return false;
        }
        $foreignKeyFailure = false;
        foreach ($steps as $offset => $step) {
            $operation = $operations[$offset];
            if ($step->state === SchemaStepStatus::Completed) {
                if (
                    !in_array(
                        $operation->kind,
                        [SchemaOperationKind::CreateTable, SchemaOperationKind::AddForeignKey],
                        true,
                    ) || !$this->physicalSchema->operationSatisfied($operation, $target)
                ) {
                    return false;
                }
                continue;
            }
            if (
                $step->state === SchemaStepStatus::Failed
                && $operation->kind === SchemaOperationKind::AddForeignKey
            ) {
                $foreignKeyFailure = true;
                continue;
            }
            if ($step->state !== SchemaStepStatus::Pending) {
                return false;
            }
        }

        return $foreignKeyFailure;
    }

    private function run(
        ExecutionContext $context,
        SchemaPlan $initialPlan,
        bool $recovery,
        bool $operatorRecovery,
    ): SchemaExecutionOutcome {
        if ($initialPlan->risk->value === 'destructive') {
            $this->authorize($context, 'business.schema.destructive');
        }
        [$target, $purge, $ownerIdentifier, $definition] = $this->target($context, $initialPlan, $recovery);
        $this->assertRecoveryEvidence($context, $initialPlan);

        try {
            $outcome = $this->lock->synchronized(
                $initialPlan->definitionId,
                function (int $fence) use (
                    $context,
                    $initialPlan,
                    $target,
                    $purge,
                    $ownerIdentifier,
                    $definition,
                    $recovery,
                    $operatorRecovery,
                ): SchemaExecutionOutcome {
                    $plan = $this->requiredPlan($context, $initialPlan->id);
                    if ($recovery) {
                        if (
                            !in_array(
                                $plan->status,
                                [
                                    SchemaPlanStatus::Executing,
                                    SchemaPlanStatus::Failed,
                                    SchemaPlanStatus::RecoveryRequired,
                                ],
                                true,
                            )
                        ) {
                            throw new BusinessSchemaConflict('The schema plan is no longer recoverable.');
                        }
                        $priorFence = $plan->executionFence;
                        $next = $plan->resume($fence, $this->clock->now());
                        $this->journal(fn () => $this->plans->replace($next, $plan->revision, $priorFence));
                        $plan = $next;
                    } else {
                        if ($plan->status !== SchemaPlanStatus::Approved) {
                            throw new BusinessSchemaConflict('The schema plan changed before lock acquisition.');
                        }
                        $next = $plan->begin($fence, $this->clock->now());
                        $this->journal(fn () => $this->plans->replace($next, $plan->revision));
                        $plan = $next;
                    }

                    try {
                        if ($plan->fromSchemaChecksum !== null) {
                            $source = $this->installations->find($plan->definitionId)
                            ?? throw new BusinessSchemaConflict('The upgrade source installation disappeared.');
                            $this->assertSourceInstallationBinding(
                                $context,
                                $plan,
                                $source,
                                $ownerIdentifier,
                            );
                            $this->prepareSourceInstallation(
                                $context,
                                $source,
                                $ownerIdentifier,
                                $purge,
                            );
                        } else {
                            $partial = $this->installations->find($plan->definitionId);
                            if ($partial !== null) {
                                if (!$recovery) {
                                    throw new BusinessSchemaConflict(
                                        'An initial schema installation appeared after approval.',
                                    );
                                }
                                $this->assertInitialRecoveryInstallation(
                                    $context,
                                    $plan,
                                    $partial,
                                    $ownerIdentifier,
                                    $target,
                                );
                            }
                            $this->prepareInitialInstallation(
                                $context,
                                $plan,
                                $partial,
                                $ownerIdentifier,
                            );
                        }

                        $steps = $this->validatedSteps($plan);
                    } catch (Throwable $failure) {
                        $this->recordInterruption($plan, $fence, $failure);
                        throw $failure;
                    }
                    $completed = 0;
                    $skipped = 0;
                    $chain = $plan->fromSchemaChecksum ?? self::EMPTY_SCHEMA_CHECKSUM;
                    $running = null;
                    $installingRecorded = $this->installations->find($plan->definitionId) !== null;
                    try {
                        foreach ($plan->operations() as $offset => $operation) {
                            $step = $steps[$offset];
                            if ($step->state === SchemaStepStatus::Completed) {
                                $chain = $step->afterSchemaChecksum
                                ?? throw new BusinessSchemaConflict('A completed journal step has no checksum.');
                                ++$skipped;
                                continue;
                            }

                            $expectedFence = $step->executionFence;
                            $step = $step->state === SchemaStepStatus::Pending
                            ? $step->start($fence, $chain, $this->clock->now())
                            : $step->resume($fence, $this->clock->now());
                            $this->journal(fn () => $this->plans->replaceStep($step, $expectedFence));
                            $running = $step;

                            if (
                                $operation->kind === SchemaOperationKind::AddForeignKey
                                && $plan->fromSchemaChecksum === null
                                && !$installingRecorded
                            ) {
                                $partial = $this->withoutForeignKeys($target);
                                $inspectedPartial = $this->physicalSchema->inspect($partial);
                                if (
                                    $inspectedPartial === null
                                    || !hash_equals($inspectedPartial->checksum(), $partial->checksum())
                                ) {
                                    throw new BusinessSchemaConflict(
                                        'Initial entity tables must exist exactly before graph foreign keys '
                                        . 'are deferred.',
                                    );
                                }
                                $now = $this->clock->now();
                                $installing = new SchemaInstallation(
                                    $plan->definitionId,
                                    $plan->siteIdentifier,
                                    $ownerIdentifier,
                                    $plan->toDefinitionVersion,
                                    $plan->toDefinitionChecksum,
                                    $partial->checksum(),
                                    $partial,
                                    SchemaInstallationStatus::Installing,
                                    $now,
                                    $now,
                                );
                                $this->journal(fn () => $this->installations->save($installing));
                                $installingRecorded = true;
                            }

                            $isRewrite = in_array(
                                $operation->kind,
                                [
                                SchemaOperationKind::Backfill,
                                SchemaOperationKind::Transform,
                                SchemaOperationKind::RepinRecords,
                                ],
                                true,
                            );
                            if ($this->environment->databaseDriver() === 'pgsql') {
                                if ($isRewrite) {
                                    [$step, $chain] = $this->rewritePostgres(
                                        $step,
                                        $operation,
                                        $target,
                                        $definition,
                                        $fence,
                                        $chain,
                                    );
                                } else {
                                    [$step, $chain] = $this->ordinaryPostgres(
                                        $step,
                                        $operation,
                                        $target,
                                        $fence,
                                        $chain,
                                    );
                                }
                            } else {
                                $alreadySatisfied = $operation->kind !== SchemaOperationKind::Transform
                                && $this->physicalSchema->operationSatisfied($operation, $target);
                                $processed = 0;
                                if (!$alreadySatisfied) {
                                    if (
                                        in_array($operation->kind, [
                                        SchemaOperationKind::Backfill,
                                        SchemaOperationKind::Transform,
                                        SchemaOperationKind::RepinRecords,
                                        ], true)
                                    ) {
                                        [$step, $processed] = $this->rewrite(
                                            $step,
                                            $operation,
                                            $target,
                                            $definition,
                                            $fence,
                                        );
                                    } else {
                                        $this->physicalSchema->execute($operation, $target);
                                        if (!$this->physicalSchema->operationSatisfied($operation, $target)) {
                                            throw new BusinessSchemaConflict(
                                                'A physical schema operation did not satisfy its approved '
                                                . 'postcondition.',
                                            );
                                        }
                                    }
                                }
                                $chain = $this->nextChain($chain, $operation, $fence, $alreadySatisfied);
                                $step = $step->complete($chain, [
                                'already_satisfied' => $alreadySatisfied,
                                'processed_rows' => $processed,
                                'fence' => $fence,
                                'transactional_ddl' => false,
                                ], $this->clock->now());
                                $this->journal(fn () => $this->plans->replaceStep($step, $fence));
                            }
                            $running = null;
                            ++$completed;
                        }

                        $completedAt = $this->clock->now();
                        $installation = null;
                        if ($purge) {
                            $schemaChecksum = BusinessSchemaPlanner::PURGED_SCHEMA_CHECKSUM;
                        } else {
                            $inspected = $this->physicalSchema->inspect($target);
                            if (
                                $inspected === null
                                || !hash_equals($inspected->checksum(), $plan->targetSchemaChecksum)
                            ) {
                                throw new BusinessSchemaConflict(
                                    'The final physical schema does not match the approved blueprint checksum.',
                                );
                            }
                            $priorInstallation = $this->installations->find($plan->definitionId);
                            $installation = new SchemaInstallation(
                                $plan->definitionId,
                                $plan->siteIdentifier,
                                $ownerIdentifier,
                                $plan->toDefinitionVersion,
                                $plan->toDefinitionChecksum,
                                $target->checksum(),
                                $target,
                                SchemaInstallationStatus::Active,
                                $priorInstallation?->installedAt ?? $completedAt,
                                $completedAt,
                            );
                            $schemaChecksum = $target->checksum();
                        }
                        $result = new SchemaExecutionOutcome(
                            $plan->id,
                            $fence,
                            $completed,
                            $skipped,
                            $schemaChecksum,
                            $completedAt,
                            $recovery,
                        );
                        $finished = $plan->complete($result->toArray(), $completedAt);
                        $this->journal(function () use (
                            $purge,
                            $plan,
                            $installation,
                            $finished,
                            $fence,
                            $context,
                            $recovery,
                            $operatorRecovery,
                            $ownerIdentifier,
                            $result,
                        ): void {
                            $ownerActive = $this->executionState->lockOwner(
                                $context->site(),
                                $plan->definitionId,
                                $ownerIdentifier,
                                false,
                            );
                            $status = $this->executionState->lockInstallationStatus($plan->definitionId);
                            if ($purge) {
                                if (
                                    !in_array($status, [
                                    SchemaInstallationStatus::Active,
                                    SchemaInstallationStatus::Installing,
                                    SchemaInstallationStatus::Disabled,
                                    SchemaInstallationStatus::Preserved,
                                    ], true)
                                ) {
                                    throw new BusinessSchemaConflict(
                                        'The purge source installation changed during execution.',
                                    );
                                }
                                $this->installations->remove($plan->definitionId, $plan->siteIdentifier);
                            } else {
                                $allowedStatus = $plan->fromSchemaChecksum === null
                                ? [null, SchemaInstallationStatus::Installing, SchemaInstallationStatus::Preserved]
                                : [SchemaInstallationStatus::Installing, SchemaInstallationStatus::Preserved];
                                if (!in_array($status, $allowedStatus, true)) {
                                    throw new BusinessSchemaConflict(
                                        'The schema installation lifecycle changed during execution.',
                                    );
                                }
                                $finalInstallation = $installation
                                ?? throw new \LogicException('Schema finalization lost its installation state.');
                                $this->installations->save(
                                    $ownerActive
                                        ? $finalInstallation
                                        : $finalInstallation->preserve($result->completedAt),
                                );
                            }
                            $this->plans->replace($finished, $plan->revision, $fence);
                            $this->audit->record(new AuditEvent(
                                Uuid::uuid7()->toString(),
                                $result->completedAt,
                                $context->actorId(),
                                $operatorRecovery ? 'business.schema.recover' : 'business.schema.execute',
                                'business_schema_plan',
                                $plan->id,
                                'success',
                                $result->toArray(),
                            ));
                        });

                        return $result;
                    } catch (Throwable $failure) {
                        $failedAt = $this->clock->now();
                        if ($running instanceof SchemaPlanStep && $running->state === SchemaStepStatus::Running) {
                            try {
                                $failed = $running->fail('schema_execution_interrupted', [
                                'failure_digest' => hash('sha256', get_class($failure)),
                                'fence' => $fence,
                                ], $failedAt);
                                $this->journal(fn () => $this->plans->replaceStep($failed, $fence));
                            } catch (Throwable) {
                                // A stale fence must never be allowed to overwrite the current executor journal.
                            }
                        }
                        $interrupted = $this->recordInterruption($plan, $fence, $failure, $failedAt);
                        if ($interrupted instanceof SchemaPlan && $this->canCompensateInitial($plan)) {
                            try {
                                $journal = $this->plans->steps($plan->id);
                                $operations = $plan->operations();
                                $created = [];
                                foreach ($journal as $offset => $journalStep) {
                                    if ($journalStep->state === SchemaStepStatus::Completed) {
                                        $created[] = $operations[$offset];
                                    }
                                }
                                foreach (array_reverse($created) as $createdOperation) {
                                    $this->physicalSchema->compensateCreateTable($createdOperation);
                                }
                                $installation = $this->installations->find($plan->definitionId);
                                if ($installation?->status === SchemaInstallationStatus::Installing) {
                                    $this->journal(fn () => $this->installations->remove(
                                        $plan->definitionId,
                                        $plan->siteIdentifier,
                                    ));
                                }
                                $compensated = $interrupted->compensate([
                                'reason' => 'initial_additive_failure',
                                'removed_created_tables' => count($created),
                                'fence' => $fence,
                                ], $this->clock->now());
                                $this->journal(fn () => $this->plans->replace(
                                    $compensated,
                                    $interrupted->revision,
                                    $fence,
                                ));
                            } catch (Throwable) {
                                // Any uncertainty leaves the durable plan recovery-required and preserves all data.
                            }
                        }
                        throw $failure;
                    }
                },
            );
        } catch (Throwable $failure) {
            $failedAt = $this->clock->now();
            $this->journal(fn () => $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $failedAt,
                $context->actorId(),
                $operatorRecovery ? 'business.schema.recover' : 'business.schema.execute',
                'business_schema_plan',
                $initialPlan->id,
                'rejected',
                ['failure_digest' => hash('sha256', get_class($failure))],
            )));
            throw $failure;
        }

        return $outcome;
    }

    private function prepareSourceInstallation(
        ExecutionContext $context,
        SchemaInstallation $source,
        string $ownerIdentifier,
        bool $purge,
    ): void {
        $this->journal(function () use ($context, $source, $ownerIdentifier, $purge): void {
            $ownerActive = $this->executionState->lockOwner(
                $context->site(),
                $source->definitionId,
                $ownerIdentifier,
                false,
            );
            $status = $this->executionState->lockInstallationStatus($source->definitionId);
            if ($status !== $source->status) {
                throw new BusinessSchemaConflict('The source installation changed before schema execution.');
            }
            $allowed = $purge
                ? [
                    SchemaInstallationStatus::Active,
                    SchemaInstallationStatus::Installing,
                    SchemaInstallationStatus::Disabled,
                    SchemaInstallationStatus::Preserved,
                ]
                : ($ownerActive
                    ? [SchemaInstallationStatus::Active, SchemaInstallationStatus::Installing]
                    : [
                        SchemaInstallationStatus::Disabled,
                        SchemaInstallationStatus::Preserved,
                        SchemaInstallationStatus::Installing,
                    ]);
            if (!in_array($status, $allowed, true)) {
                throw new BusinessSchemaConflict('The upgrade source installation is not executable.');
            }
            if ($status === SchemaInstallationStatus::Installing) {
                return;
            }
            $this->installations->save(new SchemaInstallation(
                $source->definitionId,
                $source->siteIdentifier,
                $source->ownerIdentifier,
                $source->definitionVersion,
                $source->definitionChecksum,
                $source->schemaChecksum,
                $source->blueprint,
                SchemaInstallationStatus::Installing,
                $source->installedAt,
                $this->clock->now(),
            ));
        });
    }

    private function prepareInitialInstallation(
        ExecutionContext $context,
        SchemaPlan $plan,
        ?SchemaInstallation $partial,
        string $ownerIdentifier,
    ): void {
        $this->journal(function () use ($context, $plan, $partial, $ownerIdentifier): void {
            $ownerActive = $this->executionState->lockOwner(
                $context->site(),
                $plan->definitionId,
                $ownerIdentifier,
                false,
            );
            $status = $this->executionState->lockInstallationStatus($plan->definitionId);
            if ($status !== $partial?->status) {
                throw new BusinessSchemaConflict('The initial installation changed before schema execution.');
            }
            if ($partial === null || $status === SchemaInstallationStatus::Installing) {
                return;
            }
            if ($ownerActive || $status !== SchemaInstallationStatus::Preserved) {
                throw new BusinessSchemaConflict('The partial initial installation is not recoverable.');
            }
            $this->installations->save(new SchemaInstallation(
                $partial->definitionId,
                $partial->siteIdentifier,
                $partial->ownerIdentifier,
                $partial->definitionVersion,
                $partial->definitionChecksum,
                $partial->schemaChecksum,
                $partial->blueprint,
                SchemaInstallationStatus::Installing,
                $partial->installedAt,
                $this->clock->now(),
            ));
        });
    }

    private function recordInterruption(
        SchemaPlan $plan,
        int $fence,
        Throwable $failure,
        ?DateTimeImmutable $failedAt = null,
    ): ?SchemaPlan {
        $failedAt ??= $this->clock->now();
        try {
            $interrupted = $plan->recoveryRequired('schema_execution_interrupted', [
                'failure_digest' => hash('sha256', get_class($failure)),
                'implicit_ddl_possible' => in_array(
                    $this->environment->databaseDriver(),
                    ['mysql', 'mariadb'],
                    true,
                ),
                'fence' => $fence,
            ], $failedAt);
            $this->journal(fn () => $this->plans->replace($interrupted, $plan->revision, $fence));

            return $interrupted;
        } catch (Throwable) {
            // Preserve the authoritative execution failure if persistence fencing already rejected us.
            return null;
        }
    }

    /** @return array{PhysicalSchemaBlueprint, bool, string, ?EntityTypeDefinition} */
    private function target(ExecutionContext $context, SchemaPlan $plan, bool $recovery): array
    {
        $purge = hash_equals($plan->targetSchemaChecksum, BusinessSchemaPlanner::PURGED_SCHEMA_CHECKSUM);
        $installed = $this->installations->find($plan->definitionId);
        if ($purge) {
            $entry = $this->definitions->entry($context->site(), $plan->definitionId)
                ?? throw new BusinessSchemaNotFound($plan->definitionId);
            if (
                $installed === null || $installed->siteIdentifier !== $context->site()->identifier()
                || $installed->ownerIdentifier !== $entry->owner->identifier
                || !hash_equals($installed->schemaChecksum, $plan->fromSchemaChecksum ?? '')
            ) {
                throw new BusinessSchemaConflict('The purge source installation changed after planning.');
            }
            if (!$recovery) {
                $this->assertInstalledPhysicalState($installed);
            }

            return [$installed->blueprint, true, $entry->owner->identifier, null];
        }
        $entry = $this->definitions->entry($context->site(), $plan->definitionId)
            ?? throw new BusinessSchemaNotFound($plan->definitionId);
        $record = $this->definitions->published($context->site(), $plan->definitionId, $plan->toDefinitionVersion)
            ?? throw new BusinessSchemaNotFound($plan->definitionId);
        if (!hash_equals($record->definition->checksum(), $plan->toDefinitionChecksum)) {
            throw new BusinessSchemaConflict('The published definition changed after schema planning.');
        }
        $target = $this->compiler->compile($record->definition, $context->site());
        if (!hash_equals($target->checksum(), $plan->targetSchemaChecksum)) {
            throw new BusinessSchemaConflict('The compiled target blueprint changed after schema planning.');
        }
        if (!$recovery) {
            if ($plan->fromSchemaChecksum === null) {
                if ($installed !== null || $this->physicalSchema->inspect($target) !== null) {
                    throw new BusinessSchemaConflict('An initial plan found an untracked physical installation.');
                }
            } else {
                if ($installed === null || !hash_equals($installed->schemaChecksum, $plan->fromSchemaChecksum)) {
                    throw new BusinessSchemaConflict('The source installation changed after schema planning.');
                }
                $this->assertInstalledPhysicalState($installed);
                if (
                    $this->containsPinnedRowBreakingChange($plan)
                    && !$this->hasRecordRepin($plan)
                    && $this->physicalSchema->hasRowsPinnedBefore($installed->blueprint, $plan->toDefinitionVersion)
                ) {
                    throw new BusinessSchemaConflict(
                        'Older pinned rows appeared after approval; destructive evolution is blocked.',
                    );
                }
            }
        }

        return [$target, false, $record->definition->owner->identifier, $record->definition];
    }

    private function assertInstalledPhysicalState(SchemaInstallation $installation): void
    {
        $inspected = $this->physicalSchema->inspect($installation->blueprint);
        if ($inspected === null || !hash_equals($inspected->checksum(), $installation->schemaChecksum)) {
            throw new BusinessSchemaConflict('The installed physical schema has drifted from its persisted map.');
        }
    }

    private function assertSourceInstallationBinding(
        ExecutionContext $context,
        SchemaPlan $plan,
        SchemaInstallation $installation,
        string $ownerIdentifier,
    ): void {
        if (
            $plan->fromDefinitionVersion === null || $plan->fromDefinitionChecksum === null
            || $plan->fromSchemaChecksum === null
            || $installation->definitionId !== $plan->definitionId
            || $installation->siteIdentifier !== $context->site()->identifier()
            || $installation->siteIdentifier !== $plan->siteIdentifier
            || $installation->ownerIdentifier !== $ownerIdentifier
            || $installation->definitionVersion !== $plan->fromDefinitionVersion
            || !hash_equals($installation->definitionChecksum, $plan->fromDefinitionChecksum)
            || !hash_equals($installation->schemaChecksum, $plan->fromSchemaChecksum)
        ) {
            throw new BusinessSchemaConflict(
                'The source installation metadata no longer matches the approved schema plan.',
            );
        }
    }

    private function assertInitialRecoveryInstallation(
        ExecutionContext $context,
        SchemaPlan $plan,
        SchemaInstallation $installation,
        string $ownerIdentifier,
        PhysicalSchemaBlueprint $target,
    ): void {
        $partial = $this->withoutForeignKeys($target);
        if (
            $installation->definitionId !== $plan->definitionId
            || $installation->siteIdentifier !== $context->site()->identifier()
            || $installation->siteIdentifier !== $plan->siteIdentifier
            || $installation->ownerIdentifier !== $ownerIdentifier
            || $installation->definitionVersion !== $plan->toDefinitionVersion
            || !hash_equals($installation->definitionChecksum, $plan->toDefinitionChecksum)
            || !in_array($installation->status, [
                SchemaInstallationStatus::Installing,
                SchemaInstallationStatus::Preserved,
            ], true)
            || !hash_equals($installation->schemaChecksum, $partial->checksum())
            || !hash_equals($installation->blueprint->checksum(), $partial->checksum())
        ) {
            throw new BusinessSchemaConflict(
                'The partial initial installation metadata does not match the recoverable plan state.',
            );
        }
    }

    private function withoutForeignKeys(PhysicalSchemaBlueprint $blueprint): PhysicalSchemaBlueprint
    {
        return new PhysicalSchemaBlueprint(
            $blueprint->definitionId,
            $blueprint->definitionVersion,
            $blueprint->definitionChecksum,
            array_map(
                static fn (PhysicalTableBlueprint $table): PhysicalTableBlueprint => new PhysicalTableBlueprint(
                    $table->logicalName,
                    $table->physicalName,
                    $table->kind,
                    $table->columns(),
                    $table->primaryKey,
                    $table->indexes(),
                    [],
                    $table->options,
                ),
                $blueprint->tables(),
            ),
        );
    }

    private function assertRecoveryEvidence(ExecutionContext $context, SchemaPlan $plan): void
    {
        if (!$plan->risk->requiresRecoveryEvidence()) {
            return;
        }
        if ($plan->fromSchemaChecksum === null || $plan->recoveryEvidenceId === null) {
            throw new BusinessSchemaConflict('The plan lacks source-bound recovery evidence.');
        }
        $evidence = $this->evidence->find($context->site(), $plan->recoveryEvidenceId)
            ?? throw new BusinessSchemaNotFound($plan->recoveryEvidenceId);
        $notBefore = $this->clock->now()->sub(new DateInterval(self::RECOVERY_MAX_AGE));
        if (
            !$evidence->qualifies(
                $context->site()->identifier(),
                $this->environment->databaseDriver(),
                $this->environment->databaseServerVersion(),
                $this->environment->applicationRelease(),
                $plan->fromSchemaChecksum,
                $notBefore,
            )
        ) {
            throw new BusinessSchemaConflict('Recovery evidence is stale or does not match this release and engine.');
        }
    }

    /** @return list<SchemaPlanStep> */
    private function validatedSteps(SchemaPlan $plan): array
    {
        $steps = $this->plans->steps($plan->id);
        $operations = $plan->operations();
        if (count($steps) !== count($operations)) {
            throw new BusinessSchemaConflict('The persisted execution journal is incomplete.');
        }
        foreach ($operations as $offset => $operation) {
            $step = $steps[$offset];
            if (
                $step->ordinal !== $operation->ordinal
                || !hash_equals($step->operationChecksum, $operation->checksum())
                || $step->operationKind !== $operation->kind
                || $step->risk !== $operation->risk
            ) {
                throw new BusinessSchemaConflict('The execution journal disagrees with the immutable plan.');
            }
        }

        return $steps;
    }

    /** @return array{SchemaPlanStep, int} */
    private function rewrite(
        SchemaPlanStep $step,
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        ?EntityTypeDefinition $definition,
        int $fence,
    ): array {
        $processed = 0;
        do {
            $chunk = $this->rewriteChunk($operation, $target, $definition, $step->cursor);
            $processed += $chunk->processed;
            if ($chunk->complete) {
                break;
            }
            if ($chunk->processed === 0 || $chunk->cursor === null) {
                throw new BusinessSchemaConflict('A chunked schema rewrite made no durable progress.');
            }
            $step = $step->checkpoint($chunk->cursor, $this->clock->now());
            $this->journal(fn () => $this->plans->replaceStep($step, $fence));
        } while (true);

        if (
            $operation->kind !== SchemaOperationKind::Transform
            && !$this->physicalSchema->operationSatisfied($operation, $target)
        ) {
            throw new BusinessSchemaConflict('A schema rewrite completed without satisfying its postcondition.');
        }

        return [$step, $processed];
    }

    /** @return array{SchemaPlanStep, string} */
    private function ordinaryPostgres(
        SchemaPlanStep $step,
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        int $fence,
        string $chain,
    ): array {
        return $this->journal(function () use ($step, $operation, $target, $fence, $chain): array {
            $alreadySatisfied = $this->physicalSchema->operationSatisfied($operation, $target);
            if (!$alreadySatisfied) {
                $this->physicalSchema->execute($operation, $target);
                if (!$this->physicalSchema->operationSatisfied($operation, $target)) {
                    throw new BusinessSchemaConflict(
                        'A PostgreSQL DDL operation did not satisfy its transactional postcondition.',
                    );
                }
            }
            $nextChain = $this->nextChain($chain, $operation, $fence, $alreadySatisfied);
            $completed = $step->complete($nextChain, [
                'already_satisfied' => $alreadySatisfied,
                'processed_rows' => 0,
                'fence' => $fence,
                'transactional_ddl' => true,
            ], $this->clock->now());
            $this->plans->replaceStep($completed, $fence);

            return [$completed, $nextChain];
        });
    }

    /** @return array{SchemaPlanStep, string} */
    private function rewritePostgres(
        SchemaPlanStep $step,
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        ?EntityTypeDefinition $definition,
        int $fence,
        string $chain,
    ): array {
        $processed = 0;
        do {
            [$step, $chunkProcessed, $complete, $nextChain] = $this->journal(
                function () use ($step, $operation, $target, $definition, $fence, $chain, $processed): array {
                    if (
                        $operation->kind !== SchemaOperationKind::Transform
                        && $this->physicalSchema->operationSatisfied($operation, $target)
                    ) {
                        $chunk = new SchemaChunkResult($step->cursor, 0, true);
                        $alreadySatisfied = $processed === 0;
                    } else {
                        $chunk = $this->rewriteChunk($operation, $target, $definition, $step->cursor);
                        $alreadySatisfied = false;
                    }
                    if (!$chunk->complete) {
                        if ($chunk->processed === 0 || $chunk->cursor === null) {
                            throw new BusinessSchemaConflict(
                                'A transactional schema rewrite made no durable keyset progress.',
                            );
                        }
                        $checkpoint = $step->checkpoint($chunk->cursor, $this->clock->now());
                        $this->plans->replaceStep($checkpoint, $fence);

                        return [$checkpoint, $chunk->processed, false, $chain];
                    }
                    if (
                        $operation->kind !== SchemaOperationKind::Transform
                        && !$this->physicalSchema->operationSatisfied($operation, $target)
                    ) {
                        throw new BusinessSchemaConflict(
                            'A PostgreSQL schema rewrite failed its transactional postcondition.',
                        );
                    }
                    $resultChain = $this->nextChain($chain, $operation, $fence, $alreadySatisfied);
                    $completed = $step->complete($resultChain, [
                        'already_satisfied' => $alreadySatisfied,
                        'processed_rows' => $processed + $chunk->processed,
                        'fence' => $fence,
                        'transactional_ddl' => true,
                    ], $this->clock->now());
                    $this->plans->replaceStep($completed, $fence);

                    return [$completed, $chunk->processed, true, $resultChain];
                },
            );
            $processed += $chunkProcessed;
            if ($complete) {
                return [$step, $nextChain];
            }
        } while (true);
    }

    /** @param array<string, bool|int|string>|null $cursor */
    private function rewriteChunk(
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        ?EntityTypeDefinition $definition,
        ?array $cursor,
    ): SchemaChunkResult {
        return match ($operation->kind) {
            SchemaOperationKind::Backfill => $this->physicalSchema->backfillChunk(
                $operation,
                $target,
                $cursor,
                self::CHUNK_SIZE,
            ),
            SchemaOperationKind::Transform => $this->physicalSchema->transformChunk(
                $operation,
                $target,
                $cursor,
                self::CHUNK_SIZE,
            ),
            SchemaOperationKind::RepinRecords => $this->recordRepins->repinChunk(
                $definition ?? throw new BusinessSchemaConflict('A record-repin operation has no definition.'),
                $operation,
                $target,
                $cursor,
                self::CHUNK_SIZE,
            ),
            default => throw new BusinessSchemaConflict('A non-rewrite operation entered chunked execution.'),
        };
    }

    private function nextChain(
        string $chain,
        SchemaOperation $operation,
        int $fence,
        bool $alreadySatisfied,
    ): string {
        return hash('sha256', implode("\0", [
            $chain,
            $operation->checksum(),
            (string) $fence,
            $alreadySatisfied ? 'already-satisfied' : 'applied',
        ]));
    }

    private function canCompensateInitial(SchemaPlan $plan): bool
    {
        if ($plan->fromSchemaChecksum !== null || $plan->risk->value === 'destructive') {
            return false;
        }
        $operations = $plan->operations();
        foreach ($operations as $operation) {
            if ($operation->kind === SchemaOperationKind::AddForeignKey) {
                // A graph bootstrap may intentionally preserve its empty Installing table for peer plans.
                return false;
            }
        }
        $steps = $this->plans->steps($plan->id);
        $created = 0;
        foreach ($steps as $offset => $step) {
            if ($step->state !== SchemaStepStatus::Completed) {
                continue;
            }
            $operation = $operations[$offset] ?? null;
            if (
                $operation === null
                || $operation->kind !== SchemaOperationKind::CreateTable
                || $operation->recoveryImplication !== 'compensate_safe_addition'
            ) {
                return false;
            }
            ++$created;
        }

        return $created > 0;
    }

    private function containsPinnedRowBreakingChange(SchemaPlan $plan): bool
    {
        foreach ($plan->operations() as $operation) {
            if (
                in_array(
                    $operation->kind,
                    [
                    SchemaOperationKind::DropTable,
                    SchemaOperationKind::DropColumn,
                    SchemaOperationKind::Transform,
                    SchemaOperationKind::RenameColumn,
                    ],
                    true,
                )
            ) {
                return true;
            }
            if (
                $operation->kind === SchemaOperationKind::AlterColumn
                && !$this->additiveColumnRelaxation($operation)
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasRecordRepin(SchemaPlan $plan): bool
    {
        $operations = $plan->operations();
        $repin = false;
        foreach ($operations as $operation) {
            if (
                $operation->kind === SchemaOperationKind::RepinRecords
                && ($operation->after['definition_version'] ?? null) === $plan->toDefinitionVersion
            ) {
                $repin = true;
            }
            if ($operation->kind === SchemaOperationKind::DropTable) {
                return false;
            }
            if ($operation->kind === SchemaOperationKind::DropColumn) {
                $transform = false;
                foreach ($operations as $candidate) {
                    if (
                        $candidate->kind === SchemaOperationKind::Transform
                        && $candidate->subject === $operation->subject . '.transform'
                    ) {
                        $transform = true;
                        break;
                    }
                }
                if (!$transform) {
                    return false;
                }
            }
        }

        return $repin;
    }

    private function additiveColumnRelaxation(SchemaOperation $operation): bool
    {
        if ($operation->before === null || $operation->after === null) {
            return false;
        }
        $before = \Kumwe\CMS\BusinessSchema\Domain\PhysicalColumnBlueprint::fromArray($operation->before);
        $after = \Kumwe\CMS\BusinessSchema\Domain\PhysicalColumnBlueprint::fromArray($operation->after);
        if (
            $before->logicalName !== $after->logicalName
            || $before->physicalName !== $after->physicalName
            || $before->doctrineType !== $after->doctrineType
            || ($before->nullable && !$after->nullable)
        ) {
            return false;
        }
        $old = $before->options;
        $new = $after->options;
        unset($old['default'], $new['default']);
        foreach (['length', 'precision'] as $key) {
            if (isset($old[$key]) && (!isset($new[$key]) || $new[$key] < $old[$key])) {
                return false;
            }
            unset($old[$key], $new[$key]);
        }

        return $old === $new;
    }

    private function requiredPlan(ExecutionContext $context, string $planId): SchemaPlan
    {
        return $this->plans->find($context->site(), $planId) ?? throw new BusinessSchemaNotFound($planId);
    }

    private function authorize(ExecutionContext $context, string $capability): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString($capability),
            AuthorizationResource::collection('business_schema'),
        );
    }

    /** @template T @param callable(): T $operation @return T */
    private function journal(callable $operation): mixed
    {
        return $this->transactions->transactional($operation);
    }
}
