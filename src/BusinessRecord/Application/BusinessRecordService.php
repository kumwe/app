<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Automation\IdempotencyKey;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\BusinessDefinition\Domain\ActionDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\ComputationMode;
use Kumwe\CMS\BusinessDefinition\Domain\DeleteBehavior;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\CMS\BusinessDefinition\Domain\Sensitivity;
use Kumwe\CMS\BusinessRecord\Application\Command\ArchiveRecordCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\DeleteRecordCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\ExecuteRecordActionCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\ReorderRecordLinesCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\RestoreRecordCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\UnrelateRecordsCommand;
use Kumwe\CMS\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordActionRejected;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordIdempotencyConflict;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordIdempotencyRace;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRelationshipRejected;
use Kumwe\CMS\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\CMS\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\CMS\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecord;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordIdempotency;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordIdempotencyState;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordRevision;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimal;
use Kumwe\CMS\BusinessRecord\Domain\RecordScope;
use Kumwe\CMS\BusinessRecord\Domain\RecordValueGuard;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/** The sole transactional application boundary for typed business records. */
final readonly class BusinessRecordService
{
    private const INBOUND_DELETE_LIMIT = 500;

    public function __construct(
        private BusinessRecordWriteRepository $writes,
        private BusinessRecordReadRepository $reads,
        private BusinessRecordRevisionRepository $revisions,
        private BusinessRecordIdempotencyRepository $idempotency,
        private BusinessRecordMutationFence $mutationFence,
        private BusinessRecordDefinitionResolver $definitions,
        private RecordValueCodec $values,
        private RecordRuleValidator $rules,
        private AuthorizationGateway $authorization,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private RecordFingerprint $fingerprints,
        private ClockInterface $clock,
    ) {
    }

    public function create(CreateRecordCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.create');

        return $this->idempotent(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.create',
            $command->idempotencyKey,
            [
                'definition' => $command->definitionIdentifier,
                'record_id' => $command->recordId,
                'values' => $command->values,
            ],
            function (
                DateTimeImmutable $now,
                BusinessRecordMutationGeneration $generation,
            ) use ($command): RecordMutationResult {
                $resolved = $this->definitions->forCreate($command->context, $command->definitionIdentifier);
                $generation->assertMatches($resolved);
                $scope = $this->scope($resolved, $command->context, $command->organizationIdentifier);
                try {
                    $recordId = $this->values->identity(
                        $resolved->definition,
                        $command->values,
                        $command->recordId,
                    );
                } catch (InvalidArgumentException $exception) {
                    throw new BusinessRecordValidationFailed([
                        new ValidationViolation(
                            $this->identityField($resolved->definition)->handle,
                            'identity',
                            $exception->getMessage(),
                        ),
                    ]);
                }
                $recordKey = $resolved->definition->identityStrategy === IdentityStrategy::Uuid
                    ? $recordId
                    : Uuid::uuid7()->toString();
                $values = $this->rules->create(
                    $resolved->definition,
                    $command->values,
                    $resolved->definition->siteIdentifier,
                    $recordKey,
                    $recordId,
                );
                $values = $this->resolveEntityReferences(
                    $command->context,
                    $resolved->definition,
                    $scope,
                    $values,
                    array_keys($values),
                );
                $record = new BusinessRecord(
                    $resolved->definition->id,
                    $resolved->definition->definitionVersion,
                    $recordKey,
                    $recordId,
                    $scope,
                    1,
                    $resolved->definition->workflow?->initialState,
                    $values,
                    $command->context->actorId(),
                    $now,
                    $command->context->actorId(),
                    $now,
                );
                $this->writes->insert($resolved, $record);
                $changed = array_keys($record->values());
                $this->recordMutation($command->context, $resolved, $record, 'create', $changed, $now);

                return $this->result($record, 'create');
            },
        );
    }

    public function read(ReadRecordQuery $query): BusinessRecordView
    {
        $this->authorize($query->context, 'business.record.read');

        return $this->transactions->transactional(function () use ($query): BusinessRecordView {
            $generation = $this->mutationFence->shared(
                $query->context->site(),
                $query->definitionIdentifier,
            );
            [$resolved, , $record] = $this->load(
                $query->context,
                $query->definitionIdentifier,
                $query->recordId,
                $query->organizationIdentifier,
                $query->includeArchived,
                $query->includeDeleted,
                $generation,
            );

            return $this->reads->view($resolved, $record->scope, $record, $query->projection);
        });
    }

    public function browse(BrowseRecordsQuery $query): RecordBrowseResult
    {
        $this->authorize($query->context, 'business.record.browse');

        return $this->transactions->transactional(function () use ($query): RecordBrowseResult {
            $generation = $this->mutationFence->shared(
                $query->context->site(),
                $query->definitionIdentifier,
            );
            $resolved = $this->definitions->forCreate($query->context, $query->definitionIdentifier);
            $generation->assertMatches($resolved);
            $scope = $this->scope($resolved, $query->context, $query->organizationIdentifier);

            return $this->reads->browse($resolved, $scope, $query->specification);
        });
    }

    public function update(UpdateRecordCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.update');

        return $this->idempotent(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.update',
            $command->idempotencyKey,
            [
                'definition' => $command->definitionIdentifier,
                'record_id' => $command->recordId,
                'expected_version' => $command->expectedVersion,
                'values' => $command->values,
            ],
            function (
                DateTimeImmutable $now,
                BusinessRecordMutationGeneration $generation,
            ) use ($command): RecordMutationResult {
                [$resolved, $scope, $record] = $this->load(
                    $command->context,
                    $command->definitionIdentifier,
                    $command->recordId,
                    $command->organizationIdentifier,
                    generation: $generation,
                );
                $this->expected($record, $command->expectedVersion);
                $values = $this->rules->update(
                    $resolved->definition,
                    $record->values(),
                    $command->values,
                    $resolved->definition->siteIdentifier,
                    $record->recordKey,
                    $record->recordId,
                );
                $values = $this->resolveEntityReferences(
                    $command->context,
                    $resolved->definition,
                    $scope,
                    $values,
                    array_keys($command->values),
                );
                $updated = $record->updated($values, $command->context->actorId(), $now);
                $changed = $this->changed($record->values(), $updated->values());
                $this->writes->update($resolved, $updated, $command->expectedVersion);
                $this->recordMutation($command->context, $resolved, $updated, 'update', $changed, $now);

                return $this->result($updated, 'update');
            },
        );
    }

    public function archive(ArchiveRecordCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.archive');

        return $this->lifecycle(
            $command->context,
            $command->definitionIdentifier,
            $command->recordId,
            $command->organizationIdentifier,
            $command->expectedVersion,
            $command->idempotencyKey,
            'archive',
        );
    }

    public function delete(DeleteRecordCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.delete');

        return $this->lifecycle(
            $command->context,
            $command->definitionIdentifier,
            $command->recordId,
            $command->organizationIdentifier,
            $command->expectedVersion,
            $command->idempotencyKey,
            'delete',
        );
    }

    public function restore(RestoreRecordCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.restore');

        return $this->lifecycle(
            $command->context,
            $command->definitionIdentifier,
            $command->recordId,
            $command->organizationIdentifier,
            $command->expectedVersion,
            $command->idempotencyKey,
            'restore',
        );
    }

    public function action(ExecuteRecordActionCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.action');
        if ($command->input !== []) {
            throw new BusinessRecordActionRejected('This definition declares no typed action input.');
        }

        return $this->idempotent(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.action',
            $command->idempotencyKey,
            [
                'definition' => $command->definitionIdentifier,
                'record_id' => $command->recordId,
                'expected_version' => $command->expectedVersion,
                'action' => $command->action,
                'input' => $command->input,
            ],
            function (
                DateTimeImmutable $now,
                BusinessRecordMutationGeneration $generation,
            ) use ($command): RecordMutationResult {
                [$resolved, , $record] = $this->load(
                    $command->context,
                    $command->definitionIdentifier,
                    $command->recordId,
                    $command->organizationIdentifier,
                    generation: $generation,
                );
                $this->expected($record, $command->expectedVersion);
                $action = $this->actionDefinition($resolved->definition, $command->action);
                $this->authorization->assertAllowed(
                    $command->context,
                    Capability::fromString($action->capability),
                    AuthorizationResource::collection('business_record'),
                );
                if (
                    $action->condition !== null
                    && $action->condition->evaluate($this->expressionValues($record)) !== true
                ) {
                    throw new BusinessRecordActionRejected('The action precondition rejected this record.');
                }
                if ($action->transition === null || $resolved->definition->workflow === null) {
                    throw new BusinessRecordActionRejected('The action has no executable workflow transition.');
                }
                $transition = null;
                foreach ($resolved->definition->workflow->transitions as $candidate) {
                    if ($candidate['handle'] === $action->transition && $candidate['from'] === $record->workflowState) {
                        $transition = $candidate;
                        break;
                    }
                }
                if ($transition === null) {
                    throw new BusinessRecordActionRejected(
                        'The workflow transition is invalid from the current state.',
                    );
                }
                $this->authorization->assertAllowed(
                    $command->context,
                    Capability::fromString($transition['capability']),
                    AuthorizationResource::collection('business_record'),
                );
                $updated = $record->transitioned($transition['to'], $command->context->actorId(), $now);
                $this->writes->update($resolved, $updated, $command->expectedVersion);
                $this->recordMutation(
                    $command->context,
                    $resolved,
                    $updated,
                    'action.' . $action->handle,
                    [],
                    $now,
                );

                return $this->result($updated, 'action');
            },
        );
    }

    public function relate(RelateRecordsCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.relate');

        return $this->idempotent(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.relate',
            $command->idempotencyKey,
            [
                'definition' => $command->definitionIdentifier,
                'record_id' => $command->recordId,
                'expected_version' => $command->expectedVersion,
                'relationship' => $command->relationship,
                'target_record_id' => $command->targetRecordId,
                'target_values' => $command->targetValues,
                'position' => $command->position,
            ],
            function (
                DateTimeImmutable $now,
                BusinessRecordMutationGeneration $generation,
            ) use ($command): RecordMutationResult {
                [$resolved, $scope, $source] = $this->load(
                    $command->context,
                    $command->definitionIdentifier,
                    $command->recordId,
                    $command->organizationIdentifier,
                    generation: $generation,
                );
                $this->expected($source, $command->expectedVersion);
                $relationship = $this->relationship($resolved->definition, $command->relationship);
                $targetKey = '';
                $targetResolved = null;
                $target = null;
                $lineDefinition = null;
                $lineValues = [];
                if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                    $lineResolved = $this->lineDefinition($command->context, $resolved, $relationship);
                    $lineDefinition = $lineResolved->definition;
                    try {
                        $lineId = $this->values->identity(
                            $lineDefinition,
                            $command->targetValues,
                            $command->targetRecordId,
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw new BusinessRelationshipRejected($exception->getMessage());
                    }
                    $targetKey = $lineDefinition->identityStrategy === IdentityStrategy::Uuid
                        ? $lineId
                        : Uuid::uuid7()->toString();
                    $lineValues = $this->rules->create(
                        $lineDefinition,
                        $command->targetValues,
                        $lineDefinition->siteIdentifier,
                        $targetKey,
                        $lineId,
                    );
                    $lineValues = $this->resolveEntityReferences(
                        $command->context,
                        $lineDefinition,
                        $scope,
                        $lineValues,
                        array_keys($lineValues),
                    );
                } else {
                    if ($command->targetValues !== []) {
                        throw new BusinessRelationshipRejected('Only owned lines accept embedded target values.');
                    }
                    $targetGeneration = $this->mutationFence->lock(
                        $command->context,
                        $relationship->target,
                    );
                    [$targetResolved, , $target] = $this->load(
                        $command->context,
                        $relationship->target,
                        $command->targetRecordId,
                        $command->organizationIdentifier,
                        generation: $targetGeneration,
                    );
                    $this->sameScope($source, $target);
                    $targetKey = $target->recordKey;
                }
                $write = $this->writes->relate(
                    $resolved,
                    $source,
                    $relationship,
                    $targetKey,
                    $command->position,
                    $command->context->actorId(),
                    $now,
                    $command->expectedVersion,
                    $targetResolved,
                    $target,
                    $lineDefinition,
                    $lineValues,
                );
                $updated = $write->source;
                $this->recordMutation(
                    $command->context,
                    $resolved,
                    $updated,
                    'relate.' . $relationship->handle,
                    [$relationship->handle],
                    $now,
                    $this->relationshipEvidence(
                        $relationship->handle,
                        $command->targetRecordId,
                        $command->position,
                        $command->targetValues,
                    ),
                );
                if ($write->target !== null && $targetResolved !== null && $write->targetRelationship !== null) {
                    $this->recordMutation(
                        $command->context,
                        $targetResolved,
                        $write->target,
                        'relate.' . $write->targetRelationship,
                        [$write->targetRelationship],
                        $now,
                        $this->relationshipEvidence(
                            $write->targetRelationship,
                            $command->recordId,
                            $command->position,
                        ),
                    );
                }

                return $this->result($updated, 'relate');
            },
        );
    }

    public function unrelate(UnrelateRecordsCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.relate');

        return $this->idempotent(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.unrelate',
            $command->idempotencyKey,
            [
                'definition' => $command->definitionIdentifier,
                'record_id' => $command->recordId,
                'expected_version' => $command->expectedVersion,
                'relationship' => $command->relationship,
                'target_record_id' => $command->targetRecordId,
            ],
            function (
                DateTimeImmutable $now,
                BusinessRecordMutationGeneration $generation,
            ) use ($command): RecordMutationResult {
                [$resolved, , $source] = $this->load(
                    $command->context,
                    $command->definitionIdentifier,
                    $command->recordId,
                    $command->organizationIdentifier,
                    generation: $generation,
                );
                $this->expected($source, $command->expectedVersion);
                $relationship = $this->relationship($resolved->definition, $command->relationship);
                $targetResolved = null;
                $target = null;
                if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                    $line = $this->lineDefinition($command->context, $resolved, $relationship);
                    $identity = $this->reads->ownedLineIdentity(
                        $resolved,
                        $source,
                        $relationship,
                        $line->definition,
                        $command->targetRecordId,
                    ) ?? throw new BusinessRecordNotFound();
                    $targetKey = $identity->recordKey;
                } else {
                    $targetGeneration = $this->mutationFence->lock(
                        $command->context,
                        $relationship->target,
                    );
                    [$targetResolved, , $target] = $this->load(
                        $command->context,
                        $relationship->target,
                        $command->targetRecordId,
                        $command->organizationIdentifier,
                        true,
                        true,
                        $targetGeneration,
                    );
                    $targetKey = $target->recordKey;
                }
                $write = $this->writes->unrelate(
                    $resolved,
                    $source,
                    $relationship,
                    $targetKey,
                    $command->context->actorId(),
                    $now,
                    $command->expectedVersion,
                    $targetResolved,
                    $target,
                );
                $updated = $write->source;
                $this->recordMutation(
                    $command->context,
                    $resolved,
                    $updated,
                    'unrelate.' . $relationship->handle,
                    [$relationship->handle],
                    $now,
                    $this->relationshipEvidence($relationship->handle, $command->targetRecordId),
                );
                if ($write->target !== null && $targetResolved !== null && $write->targetRelationship !== null) {
                    $this->recordMutation(
                        $command->context,
                        $targetResolved,
                        $write->target,
                        'unrelate.' . $write->targetRelationship,
                        [$write->targetRelationship],
                        $now,
                        $this->relationshipEvidence($write->targetRelationship, $command->recordId),
                    );
                }

                return $this->result($updated, 'unrelate');
            },
        );
    }

    public function reorder(ReorderRecordLinesCommand $command): RecordMutationResult
    {
        $this->authorize($command->context, 'business.record.relate');

        return $this->idempotent(
            $command->context,
            $command->definitionIdentifier,
            $command->organizationIdentifier,
            'business.record.reorder',
            $command->idempotencyKey,
            [
                'definition' => $command->definitionIdentifier,
                'record_id' => $command->recordId,
                'expected_version' => $command->expectedVersion,
                'relationship' => $command->relationship,
                'ordered_record_ids' => $command->orderedRecordIds,
            ],
            function (
                DateTimeImmutable $now,
                BusinessRecordMutationGeneration $generation,
            ) use ($command): RecordMutationResult {
                [$resolved, , $source] = $this->load(
                    $command->context,
                    $command->definitionIdentifier,
                    $command->recordId,
                    $command->organizationIdentifier,
                    generation: $generation,
                );
                $this->expected($source, $command->expectedVersion);
                $relationship = $this->relationship($resolved->definition, $command->relationship);
                $keys = [];
                $targetResolved = null;
                if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                    $line = $this->lineDefinition($command->context, $resolved, $relationship);
                    foreach ($command->orderedRecordIds as $recordId) {
                        $identity = $this->reads->ownedLineIdentity(
                            $resolved,
                            $source,
                            $relationship,
                            $line->definition,
                            $recordId,
                        ) ?? throw new BusinessRecordNotFound();
                        $keys[] = $identity->recordKey;
                    }
                } else {
                    $targetGeneration = $this->mutationFence->lock(
                        $command->context,
                        $relationship->target,
                    );
                    $targetResolved = $this->definitions->forCreate($command->context, $relationship->target);
                    $targetGeneration->assertMatches($targetResolved);
                    foreach ($command->orderedRecordIds as $recordId) {
                        [$loadedTarget, , $target] = $this->load(
                            $command->context,
                            $relationship->target,
                            $recordId,
                            $command->organizationIdentifier,
                            true,
                            true,
                            $targetGeneration,
                        );
                        if ($loadedTarget->definition->id !== $targetResolved->definition->id) {
                            throw new BusinessRecordReferenceConflict();
                        }
                        $this->sameScope($source, $target);
                        $keys[] = $target->recordKey;
                    }
                }
                if (count(array_unique($keys)) !== count($keys)) {
                    throw new BusinessRelationshipRejected('Normalized relationship identities are duplicated.');
                }
                $updated = $this->writes->reorder(
                    $resolved,
                    $source,
                    $relationship,
                    $keys,
                    $command->context->actorId(),
                    $now,
                    $command->expectedVersion,
                    $targetResolved,
                );
                $this->recordMutation(
                    $command->context,
                    $resolved,
                    $updated,
                    'reorder.' . $relationship->handle,
                    [$relationship->handle],
                    $now,
                    [
                        'relationship' => $relationship->handle,
                        'ordered_identity_digest' => $this->fingerprints->digest($command->orderedRecordIds),
                        'ordered_count' => count($command->orderedRecordIds),
                    ],
                );

                return $this->result($updated, 'reorder');
            },
        );
    }

    public function history(RecordHistoryQuery $query): RecordHistoryResult
    {
        $this->authorize($query->context, 'business.record.history');

        return $this->transactions->transactional(function () use ($query): RecordHistoryResult {
            $generation = $this->mutationFence->shared(
                $query->context->site(),
                $query->definitionIdentifier,
                true,
            );
            $installed = $this->definitions->forHistory($query->context, $query->definitionIdentifier);
            $generation->assertMatches($installed, true);
            $scope = $this->scope($installed, $query->context, $query->organizationIdentifier);
            try {
                $recordId = $this->values->identity(
                    $installed->definition,
                    [$this->identityField($installed->definition)->handle => $query->recordId],
                    null,
                );
            } catch (InvalidArgumentException) {
                throw new BusinessRecordNotFound();
            }
            $identity = $this->reads->identity($installed, $scope, $recordId, true);
            if ($identity !== null) {
                $pinned = $this->definitions->forHistory(
                    $query->context,
                    $query->definitionIdentifier,
                    $identity->definitionVersion,
                );
                $record = $this->reads->get($pinned, $scope, $recordId, true, true)
                    ?? throw new BusinessRecordNotFound();
                if (!hash_equals($identity->recordKey, $record->recordKey)) {
                    throw new BusinessRecordReferenceConflict();
                }
                $revisions = $this->revisions->history(
                    $record->definitionId,
                    $record->recordKey,
                    $query->limit + 1,
                    $query->beforeVersion,
                );
            } else {
                $revisions = $this->revisions->historyByIdentityDigest(
                    $installed->definition->id,
                    $query->context->site()->identifier(),
                    $scope->organizationIdentifier,
                    $this->fingerprints->digest($recordId),
                    $query->limit + 1,
                    $query->beforeVersion,
                );
                if ($revisions === []) {
                    throw new BusinessRecordNotFound();
                }
                $recordKeys = array_values(array_unique(array_map(
                    static fn (BusinessRecordRevision $revision): string => $revision->recordKey,
                    $revisions,
                )));
                if (count($recordKeys) !== 1) {
                    throw new BusinessRecordReferenceConflict();
                }
            }
            $hasMore = count($revisions) > $query->limit;
            if ($hasMore) {
                array_pop($revisions);
            }

            $views = [];
            foreach ($revisions as $revision) {
                $pinned = $this->definitions->forHistory(
                    $query->context,
                    $query->definitionIdentifier,
                    $revision->definitionVersion,
                );
                $views[] = BusinessRecordRevisionView::fromRevision($revision, $pinned->definition);
            }

            return new RecordHistoryResult($views, $hasMore);
        });
    }

    private function lifecycle(
        ExecutionContext $context,
        string $definitionIdentifier,
        string $recordId,
        ?string $organizationIdentifier,
        int $expectedVersion,
        IdempotencyKey $key,
        string $operation,
    ): RecordMutationResult {
        return $this->idempotent(
            $context,
            $definitionIdentifier,
            $organizationIdentifier,
            'business.record.' . $operation,
            $key,
            [
                'definition' => $definitionIdentifier,
                'record_id' => $recordId,
                'expected_version' => $expectedVersion,
            ],
            function (
                DateTimeImmutable $now,
                BusinessRecordMutationGeneration $generation,
            ) use (
                $context,
                $definitionIdentifier,
                $recordId,
                $organizationIdentifier,
                $expectedVersion,
                $operation,
            ): RecordMutationResult {
                [$resolved, , $record] = $this->load(
                    $context,
                    $definitionIdentifier,
                    $recordId,
                    $organizationIdentifier,
                    $operation !== 'archive',
                    $operation === 'restore',
                    $generation,
                );
                $this->expected($record, $expectedVersion);
                if ($operation === 'archive') {
                    $updated = $record->archived($context->actorId(), $now);
                    $this->writes->update($resolved, $updated, $expectedVersion);
                } elseif ($operation === 'restore') {
                    $updated = $record->restored($context->actorId(), $now);
                    $this->writes->update($resolved, $updated, $expectedVersion);
                } elseif ($resolved->definition->softDeleteEnabled) {
                    $updated = $record->softDeleted($context->actorId(), $now);
                    $this->writes->update($resolved, $updated, $expectedVersion);
                } else {
                    $record = $this->clearInboundSetNull($context, $resolved, $record, $now);
                    $updated = $record->softDeleted($context->actorId(), $now);
                    $this->writes->hardDelete($resolved, $record, $record->version);
                }
                $this->recordMutation($context, $resolved, $updated, $operation, [], $now);

                return $this->result($updated, $operation, $operation === 'delete');
            },
        );
    }

    private function clearInboundSetNull(
        ExecutionContext $context,
        ResolvedBusinessDefinition $targetResolved,
        BusinessRecord $target,
        DateTimeImmutable $now,
    ): BusinessRecord {
        foreach ($this->definitions->activeInstalled($context) as $candidate) {
            foreach ($candidate->definition->relationships() as $relationship) {
                if ($relationship->target !== $targetResolved->definition->handle) {
                    continue;
                }
                $direct = $candidate->installation->blueprint->table('record')?->column(
                    'relation:' . $relationship->handle . '.target_id',
                );
                if ($direct === null) {
                    continue;
                }
                if ($relationship->onDelete === DeleteBehavior::Cascade) {
                    throw new BusinessRelationshipRejected(
                        'Non-owned cascade deletion requires an explicit bounded delete workflow.',
                    );
                }
                if ($relationship->onDelete !== DeleteBehavior::SetNull) {
                    continue;
                }
                $generation = $this->mutationFence->lock($context, $candidate->definition->handle);
                $sourceInstalled = $this->definitions->forCreate($context, $candidate->definition->handle);
                $generation->assertMatches($sourceInstalled);
                $sources = $this->reads->referencing(
                    $sourceInstalled,
                    $target->scope,
                    $relationship,
                    $target->recordKey,
                    self::INBOUND_DELETE_LIMIT + 1,
                );
                if (count($sources) > self::INBOUND_DELETE_LIMIT) {
                    throw new BusinessRelationshipRejected(
                        'Inbound set-null deletion exceeds the bounded synchronous relationship limit.',
                    );
                }
                foreach ($sources as $source) {
                    $sourceResolved = $this->definitions->pinned(
                        $context,
                        $candidate->definition->handle,
                        $source->definitionVersion,
                    );
                    $generation->assertMatches($sourceResolved);
                    $write = $this->writes->unrelate(
                        $sourceResolved,
                        $source,
                        $relationship,
                        $target->recordKey,
                        $context->actorId(),
                        $now,
                        $source->version,
                        $targetResolved,
                        $target,
                    );
                    $this->recordMutation(
                        $context,
                        $sourceResolved,
                        $write->source,
                        'unrelate.' . $relationship->handle,
                        [$relationship->handle],
                        $now,
                        $this->relationshipEvidence($relationship->handle, $target->recordId),
                    );
                    if ($source->definitionId === $target->definitionId
                        && hash_equals($source->recordKey, $target->recordKey)) {
                        $target = $write->source;
                    }
                }
            }
        }

        return $target;
    }

    /**
     * @param array<string, mixed> $request
     * @param callable(DateTimeImmutable, BusinessRecordMutationGeneration): RecordMutationResult $effect
     */
    private function idempotent(
        ExecutionContext $context,
        string $definitionIdentifier,
        ?string $organizationIdentifier,
        string $operation,
        IdempotencyKey $key,
        array $request,
        callable $effect,
    ): RecordMutationResult {
        $requestFingerprint = $this->fingerprints->digest($request);
        $authorizationFingerprint = $context->authorizationFingerprint();
        $scopeDigest = $this->fingerprints->digest([
            'site' => $context->site()->identifier(),
            'organization' => $organizationIdentifier,
            'actor' => $context->actorId(),
            'operation' => $operation,
            'key' => $key->value(),
        ]);
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                return $this->transactions->transactional(function () use (
                    $context,
                    $definitionIdentifier,
                    $organizationIdentifier,
                    $operation,
                    $key,
                    $effect,
                    $scopeDigest,
                    $requestFingerprint,
                    $authorizationFingerprint,
                ): RecordMutationResult {
                    $generation = $this->mutationFence->lock($context, $definitionIdentifier);
                    $now = $this->clock->now();
                    $existing = $this->idempotency->find($scopeDigest);
                    if ($existing !== null) {
                        return $this->replay(
                            $existing,
                            $requestFingerprint,
                            $authorizationFingerprint,
                            $now,
                        );
                    }
                    $entry = new BusinessRecordIdempotency(
                        Uuid::uuid7()->toString(),
                        $scopeDigest,
                        $context->site()->identifier(),
                        $organizationIdentifier,
                        $context->actorId(),
                        $operation,
                        $key->value(),
                        $requestFingerprint,
                        $authorizationFingerprint,
                        BusinessRecordIdempotencyState::InProgress,
                        null,
                        null,
                        $now,
                        null,
                        $now->add(new DateInterval('P1D')),
                    );
                    $this->idempotency->begin($entry);
                    $result = $effect($now, $generation);
                    $resultChecksum = $this->fingerprints->digest($result->toArray());
                    $this->idempotency->complete($entry->id, $result, $resultChecksum, $now);

                    return $result;
                });
            } catch (BusinessRecordIdempotencyRace) {
                continue;
            } catch (BusinessRecordTemporarilyUnavailable $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }
        throw new BusinessRecordTemporarilyUnavailable();
    }

    private function replay(
        BusinessRecordIdempotency $entry,
        string $requestFingerprint,
        string $authorizationFingerprint,
        DateTimeImmutable $now,
    ): RecordMutationResult {
        if (!$entry->matches($requestFingerprint, $authorizationFingerprint) || $now >= $entry->expiresAt) {
            throw new BusinessRecordIdempotencyConflict('key_reused');
        }
        $result = $entry->result();
        if (!$entry->isCompleted() || $result === null) {
            throw new BusinessRecordIdempotencyConflict('in_progress');
        }
        if (
            $entry->resultChecksum === null
            || !hash_equals($entry->resultChecksum, $this->fingerprints->digest($result))
        ) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }

        try {
            return RecordMutationResult::fromArray($result)->asReplay();
        } catch (InvalidArgumentException) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }
    }

    /** @return array{ResolvedBusinessDefinition, RecordScope, BusinessRecord} */
    private function load(
        ExecutionContext $context,
        string $definitionIdentifier,
        string $recordId,
        ?string $organizationIdentifier,
        bool $includeArchived = false,
        bool $includeDeleted = false,
        ?BusinessRecordMutationGeneration $generation = null,
    ): array {
        $installed = $this->definitions->forCreate($context, $definitionIdentifier);
        $generation?->assertMatches($installed);
        $scope = $this->scope($installed, $context, $organizationIdentifier);
        try {
            $normalizedId = $this->values->identity(
                $installed->definition,
                [$this->identityField($installed->definition)->handle => $recordId],
                null,
            );
        } catch (InvalidArgumentException) {
            throw new BusinessRecordNotFound();
        }
        $identity = $this->reads->identity($installed, $scope, $normalizedId, $includeDeleted)
            ?? throw new BusinessRecordNotFound();
        $resolved = $this->definitions->pinned($context, $definitionIdentifier, $identity->definitionVersion);
        $pinnedScope = $this->scope($resolved, $context, $organizationIdentifier);
        if ($pinnedScope->toArray() !== $scope->toArray()) {
            throw new BusinessRecordReferenceConflict();
        }
        $record = $this->reads->get(
            $resolved,
            $pinnedScope,
            $normalizedId,
            $includeArchived,
            $includeDeleted,
        ) ?? throw new BusinessRecordNotFound();
        if (!hash_equals($record->recordKey, $identity->recordKey)) {
            throw new BusinessRecordReferenceConflict();
        }

        return [$resolved, $pinnedScope, $record];
    }

    private function scope(
        ResolvedBusinessDefinition $resolved,
        ExecutionContext $context,
        ?string $organizationIdentifier,
    ): RecordScope {
        try {
            return RecordScope::forDefinition(
                $resolved->definition->scope,
                $context->site(),
                $organizationIdentifier,
            );
        } catch (InvalidArgumentException $exception) {
            throw new BusinessRecordValidationFailed([
                new ValidationViolation('scope', 'scope', $exception->getMessage()),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $handles
     * @return array<string, mixed>
     */
    private function resolveEntityReferences(
        ExecutionContext $context,
        EntityTypeDefinition $definition,
        RecordScope $scope,
        array $values,
        array $handles,
    ): array {
        $violations = [];
        foreach ($definition->fields() as $field) {
            if ($field->type !== 'core.entity_reference' || !in_array($field->handle, $handles, true)) {
                continue;
            }
            $value = $values[$field->handle] ?? null;
            if ($value === null) {
                continue;
            }
            $targetHandle = $field->configuration['target'] ?? null;
            if (!is_string($value) || !is_string($targetHandle)) {
                $violations[] = new ValidationViolation(
                    $field->handle,
                    'reference',
                    'The entity reference or target definition is invalid.',
                );
                continue;
            }
            try {
                $targetGeneration = $this->mutationFence->lock($context, $targetHandle);
                $target = $this->definitions->forCreate($context, $targetHandle);
                $targetGeneration->assertMatches($target);
                $targetScope = $this->scope($target, $context, $scope->organizationIdentifier);
                $targetId = $this->values->identity(
                    $target->definition,
                    [$this->identityField($target->definition)->handle => $value],
                    null,
                );
                $identity = $this->reads->identity($target, $targetScope, $targetId);
                if ($identity === null) {
                    throw new BusinessRecordNotFound();
                }
                $values[$field->handle] = $identity->recordKey;
            } catch (BusinessRecordNotFound | InvalidArgumentException) {
                $violations[] = new ValidationViolation(
                    $field->handle,
                    'reference',
                    'The referenced business record does not exist in this scope.',
                );
            }
        }
        if ($violations !== []) {
            throw new BusinessRecordValidationFailed($violations);
        }

        return $values;
    }

    private function expected(BusinessRecord $record, int $expectedVersion): void
    {
        if ($record->version !== $expectedVersion) {
            throw new BusinessRecordVersionConflict($expectedVersion, $record->version);
        }
    }

    private function authorize(ExecutionContext $context, string $capability): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString($capability),
            AuthorizationResource::collection('business_record'),
        );
    }

    private function result(BusinessRecord $record, string $operation, bool $deleted = false): RecordMutationResult
    {
        return new RecordMutationResult(
            $record->definitionId,
            $record->definitionVersion,
            $record->recordKey,
            $record->recordId,
            $record->version,
            $record->workflowState,
            $operation,
            $deleted,
        );
    }

    /** @param list<string> $changedFields @param array<string, mixed> $evidence */
    private function recordMutation(
        ExecutionContext $context,
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $record,
        string $operation,
        array $changedFields,
        DateTimeImmutable $now,
        array $evidence = [],
    ): void {
        sort($changedFields, SORT_STRING);
        $snapshot = $this->revisionSnapshot($resolved->definition, $record);
        if ($evidence !== []) {
            if (array_key_exists('runtime_relation_evidence', $snapshot)) {
                throw new BusinessRecordSchemaUnavailable('A definition collides with reserved revision evidence.');
            }
            $snapshot['runtime_relation_evidence'] = RecordValueGuard::canonical($evidence);
        }
        if ($resolved->definition->revisionsEnabled) {
            $this->revisions->append(new BusinessRecordRevision(
                Uuid::uuid7()->toString(),
                $record->definitionId,
                $record->definitionVersion,
                $context->site()->identifier(),
                $record->scope->organizationIdentifier,
                $record->recordKey,
                $this->fingerprints->digest($record->recordId),
                $record->version,
                $record->version,
                $operation,
                $snapshot,
                $changedFields,
                $context->actorId(),
                $now,
            ));
        }
        $metadata = [];
        foreach ($changedFields as $handle) {
            $field = $this->optionalField($resolved->definition, $handle);
            $metadata[] = [
                'field' => $handle,
                'redacted' => $field !== null && in_array(
                    $field->sensitivity,
                    [Sensitivity::Restricted, Sensitivity::Secret],
                    true,
                ),
            ];
        }
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $now,
            $context->actorId(),
            'business.record.' . $operation,
            'business_record',
            $record->recordKey,
            'success',
            [
                'definition_id' => $record->definitionId,
                'definition_version' => $record->definitionVersion,
                'record_version' => $record->version,
                'record_identity_digest' => $this->fingerprints->digest($record->recordId),
                'organization_identifier' => $record->scope->organizationIdentifier,
                'changed_fields' => $metadata,
                'mutation_evidence' => RecordValueGuard::canonical($evidence),
            ],
        ));
    }

    /** @param array<string, mixed> $embeddedValues @return array<string, mixed> */
    private function relationshipEvidence(
        string $relationship,
        string $targetIdentity,
        ?int $position = null,
        array $embeddedValues = [],
    ): array {
        return [
            'relationship' => $relationship,
            'target_identity_digest' => $this->fingerprints->digest($targetIdentity),
            'position' => $position,
            'embedded_values_digest' => $embeddedValues === []
                ? null
                : $this->fingerprints->digest($embeddedValues),
        ];
    }

    /** @return array<string, mixed> */
    private function revisionSnapshot(EntityTypeDefinition $definition, BusinessRecord $record): array
    {
        $snapshot = [];
        foreach ($definition->fields() as $field) {
            if ($field->computed && $field->computationMode === ComputationMode::Virtual) {
                continue;
            }
            if (!array_key_exists($field->handle, $record->values())) {
                continue;
            }
            $snapshot[$field->handle] = RecordValueGuard::canonical($record->values()[$field->handle]);
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @return list<string> */
    private function changed(array $before, array $after): array
    {
        $handles = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
        $changed = [];
        foreach ($handles as $handle) {
            if (
                RecordValueGuard::canonical($before[$handle] ?? null)
                !== RecordValueGuard::canonical($after[$handle] ?? null)
            ) {
                $changed[] = $handle;
            }
        }
        sort($changed, SORT_STRING);

        return $changed;
    }

    private function identityField(EntityTypeDefinition $definition): FieldDefinition
    {
        $type = $definition->identityStrategy === IdentityStrategy::Uuid
            ? 'core.uuid'
            : 'core.reference_identity';
        foreach ($definition->fields() as $field) {
            if ($field->type === $type) {
                return $field;
            }
        }
        throw new BusinessRecordReferenceConflict();
    }

    private function optionalField(EntityTypeDefinition $definition, string $handle): ?FieldDefinition
    {
        foreach ($definition->fields() as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }

        return null;
    }

    private function relationship(EntityTypeDefinition $definition, string $handle): RelationshipDefinition
    {
        return $definition->runtimeRelationship($handle)
            ?? throw new BusinessRelationshipRejected('The relationship is not declared by the pinned definition.');
    }

    private function actionDefinition(EntityTypeDefinition $definition, string $handle): ActionDefinition
    {
        foreach ($definition->actions() as $action) {
            if ($action->handle === $handle) {
                return $action;
            }
        }
        throw new BusinessRecordActionRejected('The action is not declared by the pinned definition.');
    }

    private function lineDefinition(
        ExecutionContext $context,
        ResolvedBusinessDefinition $owner,
        RelationshipDefinition $relationship,
    ): ResolvedBusinessDefinition {
        $table = $owner->installation->blueprint->table('line:' . $relationship->handle)
            ?? throw new BusinessRelationshipRejected('The owned-line table is unavailable.');
        $version = $table->options['target_definition_version'] ?? null;
        if (!is_int($version)) {
            throw new BusinessRelationshipRejected('The owned-line pinned definition version is unavailable.');
        }

        $generation = $this->mutationFence->lock($context, $relationship->target);
        $line = $this->definitions->pinned($context, $relationship->target, $version);
        $generation->assertMatches($line);

        return $line;
    }

    private function sameScope(BusinessRecord $source, BusinessRecord $target): void
    {
        if ($source->scope->toArray() !== $target->scope->toArray()) {
            throw new BusinessRecordReferenceConflict();
        }
    }

    /** @return array<string, scalar|null> */
    private function expressionValues(BusinessRecord $record): array
    {
        $values = [];
        foreach ($record->values() as $handle => $value) {
            $values[$handle] = match (true) {
                $value instanceof ExactDecimal => $value->value(),
                $value instanceof DateTimeImmutable => $value->format('Y-m-d\TH:i:s.uP'),
                is_scalar($value), $value === null => $value,
                default => null,
            };
        }

        return $values;
    }
}
