<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Automation\IdempotencyKey;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\BusinessDefinition\Domain\ActionDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\ComputationMode;
use Kumwe\CMS\BusinessDefinition\Domain\DeleteBehavior;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\CMS\BusinessDefinition\Domain\ScopeMode;
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
use Kumwe\CMS\BusinessRecord\Application\Query\BusinessRecordQueryPurpose;
use Kumwe\CMS\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\CMS\BusinessRecord\Application\Query\RecordHistoryQuery;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecord;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordIdempotency;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordIdempotencyState;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordRevision;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimal;
use Kumwe\CMS\BusinessRecord\Domain\RecordScope;
use Kumwe\CMS\BusinessRecord\Domain\RecordValueGuard;
use Kumwe\CMS\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\CMS\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\CMS\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalBinding;
use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalDenied;
use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalService;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * The one transactional boundary through which typed business records are read and changed.
 *
 * Business records deliberately have no REST, console, MCP, portal or administrator adapter of their
 * own, so every caller arrives here and the same sequence runs however it came. One service-owned
 * transaction
 * fences the definition's installation so an installer cannot move the physical tables mid-operation,
 * resolves the exact definition version the operation is entitled to, authorizes the capability,
 * validates the submitted values against that version, applies the write as a compare-and-set on the
 * version the caller read, appends a revision, and records redacted audit metadata — so a refused or
 * failed operation leaves neither a row nor a trail behind.
 *
 * Every mutation is additionally claimed in the idempotency ledger before its effect runs and completed
 * with the result afterwards, which is what makes a repeated command replay its first outcome instead of
 * applying twice. Reads are pinned rather than current: a row is always decoded against the definition
 * version it was written under, so publishing a new version never re-interprets what is already stored,
 * and history stays readable after the record type itself has been withdrawn.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordService
{
    /**
     * Most inbound set-null references a hard delete will clear within its own transaction.
     *
     * The read side is asked for one row beyond this so an overflow is detected rather than silently
     * truncated. A record with more inbound references than this has its delete refused, because
     * clearing them synchronously would make one request's transaction unboundedly large.
     *
     * @var    int
     * @since  2.0.0
     */
    private const INBOUND_DELETE_LIMIT = 500;

    /**
     * Wire the service to every collaborator a record operation composes.
     *
     * @param  BusinessRecordWriteRepository        $writes         Store every record and relationship
     *         write is applied through.
     * @param  BusinessRecordReadRepository         $reads          Store rows, views and browse pages are
     *         read back from.
     * @param  BusinessRecordRevisionRepository     $revisions      Append-only log this service writes
     *         history to and pages back.
     * @param  BusinessRecordIdempotencyRepository  $idempotency    Ledger that claims a command's key and
     *         holds its result for replay.
     * @param  BusinessRecordMutationFence          $mutationFence  Lock held over a definition's
     *         installation for the whole operation.
     * @param  BusinessRecordDefinitionResolver     $definitions    Resolver pairing a published definition
     *         version with its installed schema.
     * @param  RecordValueCodec                     $values         Value codec, used here to normalize a
     *         caller-supplied record identity.
     * @param  RecordRuleValidator                  $rules          Field-rule validator that turns
     *         submitted values into a stored value set.
     * @param  BusinessRecordAccessController       $recordAccess   Canonical row, field, action, and relation
     *         policy planner applied before every repository call.
     * @param  ApprovalService                      $approvals      Generic maker-checker and step-up workflow.
     * @param  ResourceSiteOwnershipWriter          $ownership      Records approval-resource ownership with
     *         create and removes it only with a physical delete.
     * @param  AuthorizationGateway                 $authorization  Gateway asked for the operation, action
     *         and transition capabilities.
     * @param  TransactionManager                   $transactions   Owner of the single transaction each
     *         operation runs inside.
     * @param  AuditRecorder                        $audit          Sink for the redacted audit entry every
     *         mutation writes.
     * @param  RecordFingerprint                    $fingerprints   Keyed digest used for idempotency
     *         scopes and for identities held in the trail.
     * @param  ClockInterface                       $clock          Supplies the one instant stamped on
     *         every row a mutation touches.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessRecordWriteRepository $writes,
        private BusinessRecordReadRepository $reads,
        private BusinessRecordRevisionRepository $revisions,
        private BusinessRecordIdempotencyRepository $idempotency,
        private BusinessRecordMutationFence $mutationFence,
        private BusinessRecordDefinitionResolver $definitions,
        private RecordValueCodec $values,
        private RecordRuleValidator $rules,
        private BusinessRecordAccessController $recordAccess,
        private ApprovalService $approvals,
        private ResourceSiteOwnershipWriter $ownership,
        private AuthorizationGateway $authorization,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private RecordFingerprint $fingerprints,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Create one record of the command's entity type and report the version it was stored at.
     *
     * Identity is settled before anything is written: the command's explicit record id wins, otherwise the
     * value in the definition's identity field is used, and a UUID-identity type mints one when neither is
     * given. That identity also decides the row's internal storage key — a UUID-identity type reuses it,
     * while a reference-identity type is given a fresh UUID so the caller-facing id stays free to be a
     * business number. Entity references among the submitted values are exchanged for the target's
     * internal key, and the insert, the revision and the audit entry all share the one transaction.
     *
     * @param   CreateRecordCommand  $command  Validated create request: context, entity type, field values,
     *          idempotency key, and optional organization scope and identity.
     *
     * @return  RecordMutationResult  Keys, version and workflow state of the stored record; `replayed` is
     *          true when an earlier command under the same key produced it and nothing was written now.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not create
     *          business records.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
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
                $access = $this->recordAccess->plan(
                    $command->context,
                    'business.record.create',
                    $resolved,
                    $scope,
                );
                $this->assertFieldInput($access, FieldAccessUsage::Create, array_keys($command->values));
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
                if (!$access->records->allows($record->values())) {
                    throw new BusinessRecordValidationFailed([
                        new ValidationViolation('record', 'access', 'The submitted record is unavailable.'),
                    ]);
                }
                $this->writes->insert($resolved, $record);
                $this->ownership->record(
                    AuthorizationResource::item('business_record', $this->recordResourceIdentifier($record)),
                    $command->context->site(),
                );
                $changed = array_keys($record->values());
                $this->recordMutation($command->context, $resolved, $record, 'create', $changed, $now);

                return $this->result($record, 'create');
            },
        );
    }

    /**
     * Read one record by its public identity and project it to what the caller is allowed to see.
     *
     * The read takes a shared fence on the definition's installation rather than an exclusive one, so it
     * runs alongside other readers while still keeping an installer from moving the schema underneath it.
     * The row is decoded against the definition version it was written under, not the installed one, and
     * archived or soft-deleted records stay invisible unless the query opts into them.
     *
     * @param   ReadRecordQuery  $query  Validated read request: context, entity type, record identity,
     *          organization scope, projection, and the two lifecycle switches.
     *
     * @return  BusinessRecordView  The record narrowed to the readable fields the projection kept, with
     *          restricted and secret values present but redacted so withheld is distinguishable from
     *          absent.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not read
     *          business records.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When no
     *          definition on this site matches the identifier, or its owner is disabled.
     * @throws  BusinessRecordSchemaUnavailable  When the definition's schema is not installed and active,
     *          or a stored value does not match the column the blueprint describes.
     * @throws  BusinessRecordValidationFailed  When the organization scope is not one this definition
     *          accepts.
     * @throws  BusinessRecordNotFound  When no record answers that identity within the caller's scope, or
     *          the identity is not a usable one for this definition.
     * @throws  BusinessRecordReferenceConflict  When the row's pinned definition resolves to a different
     *          scope, or its storage key disagrees with the identity that addressed it.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between the resolve and
     *          the fence, or the shared lock could not be taken.
     *
     * @since   2.0.0
     */
    public function read(ReadRecordQuery $query): BusinessRecordView
    {
        $this->authorize($query->context, 'business.record.read');

        return $this->transactions->transactional(function () use ($query): BusinessRecordView {
            $generation = $this->mutationFence->shared(
                $query->context->site(),
                $query->definitionIdentifier,
            );
            [$resolved, , $record, $access] = $this->load(
                $query->context,
                $query->definitionIdentifier,
                $query->recordId,
                $query->organizationIdentifier,
                'business.record.read',
                $query->includeArchived,
                $query->includeDeleted,
                $generation,
            );

            return $this->reads->view($resolved, $record->scope, $record, $access, $query->projection);
        });
    }

    /**
     * List one bounded page of records of a definition, as the query's specification describes it.
     *
     * Only the scope is decided here: the definition is resolved at its installed version under a shared
     * fence, and the specification is handed whole to the read side, which compiles it into a single
     * statement. Rows on the page are still decoded against the version each was written under, so a page
     * may span several shapes, and requested aggregates are computed over the whole match, not the page.
     *
     * @param   BrowseRecordsQuery  $query  Validated browse request: context, entity type, organization
     *          scope, and the filter, sort, cursor and projection to compile.
     *
     * @return  RecordBrowseResult  Projected records for this page, a continuation cursor present only
     *          while further rows remain, and any aggregates the specification asked for.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not browse
     *          business records.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When no
     *          definition on this site matches the identifier, or its owner is disabled.
     * @throws  BusinessRecordSchemaUnavailable  When the definition's schema is not installed and active,
     *          or the query names something the installed columns do not carry.
     * @throws  BusinessRecordValidationFailed  When the organization scope is not one this definition
     *          accepts.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\InvalidBusinessRecordQuery  When the
     *          specification cannot be compiled, such as a cursor raised against a different query.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between the resolve and
     *          the fence, or the shared lock could not be taken.
     *
     * @since   2.0.0
     */
    public function browse(BrowseRecordsQuery $query): RecordBrowseResult
    {
        $purpose = $query->purpose;
        if (
            $purpose === BusinessRecordQueryPurpose::Browse
            && $query->specification->projection->aggregates !== []
        ) {
            $purpose = BusinessRecordQueryPurpose::Report;
        }
        $operation = 'business.record.' . $purpose->value;
        $this->authorize($query->context, $operation);

        return $this->transactions->transactional(function () use ($query, $operation): RecordBrowseResult {
            $generation = $this->mutationFence->shared(
                $query->context->site(),
                $query->definitionIdentifier,
            );
            $resolved = $this->definitions->forCreate($query->context, $query->definitionIdentifier);
            $generation->assertMatches($resolved);
            $scope = $this->scope($resolved, $query->context, $query->organizationIdentifier);
            $access = $this->recordAccess->plan(
                $query->context,
                $operation,
                $resolved,
                $scope,
            );

            return $this->reads->browse($resolved, $scope, $query->specification, $access);
        });
    }

    /**
     * Apply the command's value patch to one record and report the version it moved to.
     *
     * The patch is merged over the stored values, so only the handles the caller named are validated and
     * only entity references among those handles are re-resolved; everything else keeps what it held. What
     * the revision and the audit entry record is the set of fields whose canonical value actually differs
     * afterwards, which is not the same as the set that was submitted — resending a value unchanged, or in
     * a different spelling of the same decimal or timestamp, registers as no change at all.
     *
     * @param   UpdateRecordCommand  $command  Validated update request: context, entity type, record
     *          identity, expected version, the value patch, idempotency key and organization scope.
     *
     * @return  RecordMutationResult  Keys, new version and workflow state of the stored record; `replayed`
     *          is true when an earlier command under the same key produced it.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not update
     *          business records.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
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
                [$resolved, $scope, $record, $access] = $this->load(
                    $command->context,
                    $command->definitionIdentifier,
                    $command->recordId,
                    $command->organizationIdentifier,
                    'business.record.update',
                    generation: $generation,
                );
                $this->expected($record, $command->expectedVersion);
                $this->assertFieldInput($access, FieldAccessUsage::Update, array_keys($command->values));
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

    /**
     * Withdraw one record from ordinary reads without deleting it, and report its new version.
     *
     * Archiving is a marking rather than a removal: values, identity and workflow state survive it, and
     * `restore()` reverses it. The shared lifecycle path loads the record with archived and deleted rows
     * both out of view, so a record that is already archived, or that has been soft-deleted, is reported
     * as not found instead of being archived a second time over its original stamp.
     *
     * @param   ArchiveRecordCommand  $command  Validated archive request: context, entity type, record
     *          identity, expected version, idempotency key and organization scope.
     *
     * @return  RecordMutationResult  Keys, new version and workflow state of the archived record;
     *          `replayed` is true when an earlier command under the same key produced it.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not archive
     *          business records.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
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

    /**
     * Delete one record the way its entity type defines deletion, and report the outcome.
     *
     * The definition decides which of two paths runs. With soft delete enabled the row is marked and
     * keeps its values, so `restore()` can bring it back. Without it the row is erased outright, which
     * first requires clearing every inbound set-null reference to it — a bounded, synchronous sweep that
     * refuses rather than proceeds when too many records point at this one. An archived record can still
     * be deleted, but an already soft-deleted one is reported as not found rather than deleted twice.
     *
     * @param   DeleteRecordCommand  $command  Validated delete request: context, entity type, record
     *          identity, expected version, idempotency key and organization scope.
     *
     * @return  RecordMutationResult  Keys and new version of the record, with `deleted` raised; the
     *          version is reported even on the hard-delete path, where no row survives to carry it.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not delete
     *          business records.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
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

    /**
     * Bring an archived or soft-deleted record back into ordinary circulation.
     *
     * This is the one lifecycle operation that loads with both archived and deleted rows in view, which is
     * how it reaches a record ordinary reads hide. Restoring does not distinguish undeleting from
     * unarchiving: both stamps are cleared together, so a record that was archived and then deleted comes
     * back live rather than archived. A record that is neither archived nor deleted has nothing to
     * restore and the domain refuses it, so this is not a no-op that is safe to fire speculatively, and a
     * hard-deleted record is beyond its reach because no row survived to be found.
     *
     * @param   RestoreRecordCommand  $command  Validated restore request: context, entity type, record
     *          identity, expected version, idempotency key and organization scope.
     *
     * @return  RecordMutationResult  Keys, new version and workflow state of the live record; `replayed`
     *          is true when an earlier command under the same key produced it.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not restore
     *          business records.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
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

    /**
     * Run one definition-declared action against a record, performing the workflow transition it names.
     *
     * An action is the only route to a workflow move: a caller never sets a state directly. Three further
     * gates stand past the operation capability — the action's own capability, its optional condition
     * evaluated against the record's scalar values, and the transition's capability — and the transition
     * must be one that leaves the state the record is actually in. Because two of those turn on the record
     * rather than the definition, the same action can be accepted for one record and refused for another,
     * and retrying without a state change fails identically. Action input is rejected outright, before the
     * idempotency ledger is touched, because no definition can declare a typed input yet.
     *
     * @param   ExecuteRecordActionCommand  $command  Validated action request: context, entity type, record
     *          identity, expected version, action handle, idempotency key and organization scope.
     *
     * @return  RecordMutationResult  Keys, new version and the workflow state the record was moved into;
     *          `replayed` is true when an earlier command under the same key produced it.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor holds neither the
     *          action capability nor the transition capability, or may not run record actions at all.
     * @throws  BusinessRecordActionRejected  When the command carries action input, the pinned definition
     *          declares no such action, its condition does not evaluate to true, or it names no transition
     *          out of the state the record currently holds.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
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
                [$resolved, , $record, $access] = $this->load(
                    $command->context,
                    $command->definitionIdentifier,
                    $command->recordId,
                    $command->organizationIdentifier,
                    'business.record.action',
                    generation: $generation,
                );
                $this->expected($record, $command->expectedVersion);
                if (!$access->allowsAction($command->action)) {
                    throw new BusinessRecordNotFound();
                }
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
                    Capability::fromString('business.record.transition'),
                    AuthorizationResource::collection('business_record'),
                );
                $this->authorization->assertAllowed(
                    $command->context,
                    Capability::fromString($transition['capability']),
                    AuthorizationResource::collection('business_record'),
                );
                if ($action->highImpact) {
                    $approvalRequestId = $command->approvalRequestId ?? throw new ApprovalDenied();
                    $this->approvals->consume(
                        $command->context,
                        $approvalRequestId,
                        $this->actionApprovalBinding($command, $record, $action),
                    );
                }
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

    /**
     * Request generic maker-checker approval for one exact high-impact record action.
     *
     * The same policy-filtered load, version, action capability, condition, and workflow transition
     * checks used by execution run before the request is stored. The returned request is therefore
     * bound to a record and payload that the actor could genuinely attempt, without mutating it.
     *
     * @param   ExecuteRecordActionCommand  $command  Exact action attempt without an approval request id.
     *
     * @return  ?string  New approval UUID, or null when no active rule requires approval.
     *
     * @throws  BusinessRecordActionRejected  When the action is not high-impact or is not currently executable.
     *
     * @since   2.0.0
     */
    public function requestActionApproval(ExecuteRecordActionCommand $command): ?string
    {
        $this->authorize($command->context, 'business.record.action');
        if ($command->input !== [] || $command->approvalRequestId !== null) {
            throw new BusinessRecordActionRejected('An approval request requires an unconsumed action attempt.');
        }

        return $this->transactions->transactional(function () use ($command): ?string {
            [$resolved, , $record, $access] = $this->load(
                $command->context,
                $command->definitionIdentifier,
                $command->recordId,
                $command->organizationIdentifier,
                'business.record.action',
            );
            $this->expected($record, $command->expectedVersion);
            if (!$access->allowsAction($command->action)) {
                throw new BusinessRecordNotFound();
            }
            $action = $this->actionDefinition($resolved->definition, $command->action);
            if (!$action->highImpact) {
                throw new BusinessRecordActionRejected('The action does not require maker-checker approval.');
            }
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
                throw new BusinessRecordActionRejected('The workflow transition is invalid from the current state.');
            }
            $this->authorization->assertAllowed(
                $command->context,
                Capability::fromString('business.record.transition'),
                AuthorizationResource::collection('business_record'),
            );
            $this->authorization->assertAllowed(
                $command->context,
                Capability::fromString($transition['capability']),
                AuthorizationResource::collection('business_record'),
            );

            return $this->approvals->request(
                $command->context,
                $this->actionApprovalBinding($command, $record, $action),
            );
        });
    }

    /**
     * Link a record to another through one declared relationship, creating the owned line where there is
     * no target yet.
     *
     * The two shapes diverge only in how the far end is obtained. An owned-line collection has nothing to
     * point at, so the command's target values are validated against the pinned line type and the line is
     * created as part of the same write. Every other kind names an existing record, which is loaded under
     * its own fence and required to sit in exactly the same site and organization as the source, so a link
     * never crosses a scope boundary. The source is always re-versioned; when the relationship's canonical
     * storage belongs to the inverse side the target's row moves too, and it is then re-versioned and
     * audited under the handle that side actually stores.
     *
     * @param   RelateRecordsCommand  $command  Validated relate request: context, source entity type and
     *          identity, expected version, relationship handle, target identity or line values, position,
     *          idempotency key and organization scope.
     *
     * @return  RecordMutationResult  Keys and new version of the source record; the target's own new
     *          version, where one was written, is recorded in the trail rather than returned here.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not relate
     *          business records.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When an installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
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
                [$resolved, $scope, $source, $access] = $this->load(
                    $command->context,
                    $command->definitionIdentifier,
                    $command->recordId,
                    $command->organizationIdentifier,
                    'business.record.relate',
                    generation: $generation,
                );
                $this->expected($source, $command->expectedVersion);
                $relationship = $this->relationship($resolved->definition, $command->relationship);
                $relatedAccess = $access->related($relationship->handle)
                    ?? throw new BusinessRecordNotFound();
                $targetKey = '';
                $targetResolved = null;
                $target = null;
                $lineDefinition = null;
                $lineValues = [];
                if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                    $this->assertFieldInput(
                        $relatedAccess,
                        FieldAccessUsage::Create,
                        array_keys($command->targetValues),
                    );
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
                    if (!$lineAccess->records->allows($lineValues)) {
                        throw new BusinessRecordNotFound();
                    }
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
                        'business.record.relate',
                        generation: $targetGeneration,
                    );
                    if (
                        !hash_equals($targetResolved->definition->id, $relatedAccess->resourceIdentifier)
                        || !$relatedAccess->records->allows($target->values())
                    ) {
                        throw new BusinessRecordNotFound();
                    }
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

    /**
     * Detach one named link between a record and a single target of that relationship.
     *
     * Detaching is authorized under the same capability as linking, and neither record is deleted by it —
     * except an owned line, which has no existence apart from its owner and so goes with the link. The
     * target of an ordinary relationship is loaded with archived and soft-deleted rows in view, so a link
     * to a record that has since been withdrawn can still be broken. A link that is not there is an error
     * rather than a no-op; repeating the command safely is what the idempotency key is for. As with
     * `relate()`, a relationship whose canonical storage belongs to the inverse side moves the target's
     * row, which is then re-versioned and audited under its own handle.
     *
     * @param   UnrelateRecordsCommand  $command  Validated unrelate request: context, source entity type
     *          and identity, expected version, relationship handle, target identity, idempotency key and
     *          organization scope.
     *
     * @return  RecordMutationResult  Keys and new version of the source record; the target's own new
     *          version, where one was written, is recorded in the trail rather than returned here.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not relate
     *          business records.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When an installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
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
                [$resolved, , $source, $access] = $this->load(
                    $command->context,
                    $command->definitionIdentifier,
                    $command->recordId,
                    $command->organizationIdentifier,
                    'business.record.relate',
                    generation: $generation,
                );
                $this->expected($source, $command->expectedVersion);
                $relationship = $this->relationship($resolved->definition, $command->relationship);
                $relatedAccess = $access->related($relationship->handle)
                    ?? throw new BusinessRecordNotFound();
                $targetResolved = null;
                $target = null;
                if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                    $line = $this->lineDefinition($command->context, $resolved, $relationship);
                    $identity = $this->reads->ownedLineIdentity(
                        $resolved,
                        $source,
                        $relationship,
                        $line,
                        $relatedAccess,
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
                        'business.record.relate',
                        true,
                        true,
                        $targetGeneration,
                    );
                    if (
                        !hash_equals($targetResolved->definition->id, $relatedAccess->resourceIdentifier)
                        || !$relatedAccess->records->allows($target->values())
                    ) {
                        throw new BusinessRecordNotFound();
                    }
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

    /**
     * Put the members of one ordered relationship into the position order the command lists.
     *
     * The list is a full replacement rather than a move instruction: the write side refuses an order that
     * does not name every currently stored member exactly once. Each caller-facing identity is first
     * normalized to the storage key behind it — owned lines through their owner, other members by loading
     * each one with archived and deleted rows in view — and every member must belong to the relationship's
     * own definition and to the owner's scope, a check the owned-line lookup gets for free by being scoped
     * to the owner in the first place. Duplicates are re-checked after that normalization, since
     * two distinct identities can still resolve to one row. What the trail records is a digest of the
     * requested order and its length, never the identities themselves.
     *
     * @param   ReorderRecordLinesCommand  $command  Validated reorder request: context, entity type, owner
     *          identity, expected version, relationship handle, the new order, idempotency key and
     *          organization scope.
     *
     * @return  RecordMutationResult  Keys and new version of the owning record, whose collection is
     *          renumbered from zero in the order given.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not relate
     *          business records.
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When an installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
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
                [$resolved, , $source, $access] = $this->load(
                    $command->context,
                    $command->definitionIdentifier,
                    $command->recordId,
                    $command->organizationIdentifier,
                    'business.record.relate',
                    generation: $generation,
                );
                $this->expected($source, $command->expectedVersion);
                $relationship = $this->relationship($resolved->definition, $command->relationship);
                $relatedAccess = $access->related($relationship->handle)
                    ?? throw new BusinessRecordNotFound();
                $keys = [];
                $targetResolved = null;
                if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                    $line = $this->lineDefinition($command->context, $resolved, $relationship);
                    foreach ($command->orderedRecordIds as $recordId) {
                        $identity = $this->reads->ownedLineIdentity(
                            $resolved,
                            $source,
                            $relationship,
                            $line,
                            $relatedAccess,
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
                            'business.record.relate',
                            true,
                            true,
                            $targetGeneration,
                        );
                        if ($loadedTarget->definition->id !== $targetResolved->definition->id) {
                            throw new BusinessRecordReferenceConflict();
                        }
                        if (
                            !hash_equals($loadedTarget->definition->id, $relatedAccess->resourceIdentifier)
                            || !$relatedAccess->records->allows($target->values())
                        ) {
                            throw new BusinessRecordNotFound();
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

    /**
     * Read one bounded, newest-first window of a record's revision log.
     *
     * History outlives both the row and the record type, so this is the only operation that fences and
     * resolves in history mode, which admits a withdrawn installation and a deactivated owner. Which
     * lookup runs depends on whether the row is still there: while it resolves the log is addressed by its
     * internal storage key, and once it is gone the log is addressed by the keyed digest of the record's
     * identity instead — the only handle that survives a hard delete. A digest is not proof of a single
     * subject, so entries under one digest that disagree about the record key are refused rather than
     * merged. Each revision is rendered against the definition version it was written under, so a window
     * spanning an upgrade holds views of different shapes.
     *
     * @param   RecordHistoryQuery  $query  Validated history request: context, entity type, record
     *          identity, organization scope, window size and the version to page back from.
     *
     * @return  RecordHistoryResult  Up to `$query->limit` revision views, newest first, and whether older
     *          revisions remain — established by fetching one row past the limit and discarding it.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not read
     *          business-record history.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When no
     *          definition on this site matches the identifier, or the requested version is not published.
     * @throws  BusinessRecordSchemaUnavailable  When no retained installation exists for the definition, or
     *          a stored row disagrees with the checksum written beside it.
     * @throws  BusinessRecordValidationFailed  When the organization scope is not one this definition
     *          accepts.
     * @throws  BusinessRecordNotFound  When the identity is not usable for this definition, or neither the
     *          row nor any revision under its identity digest exists in scope.
     * @throws  BusinessRecordReferenceConflict  When the definition declares no identity field, the row's
     *          storage key disagrees with the identity that addressed it, or revisions found by digest
     *          belong to more than one record.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between the resolve and
     *          the fence, or the shared lock could not be taken.
     *
     * @since   2.0.0
     */
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
            $installedAccess = $this->recordAccess->plan(
                $query->context,
                'business.record.history',
                $installed,
                $scope,
            );
            $identity = $this->reads->identity($installed, $scope, $installedAccess, $recordId, true);
            if ($identity !== null) {
                $pinned = $this->definitions->forHistory(
                    $query->context,
                    $query->definitionIdentifier,
                    $identity->definitionVersion,
                );
                $access = $this->recordAccess->plan(
                    $query->context,
                    'business.record.history',
                    $pinned,
                    $scope,
                );
                $record = $this->reads->get($pinned, $scope, $access, $recordId, true, true)
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
                    $installed,
                    $scope,
                    $installedAccess,
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
                $latest = $revisions[0];
                $pinned = $this->definitions->forHistory(
                    $query->context,
                    $query->definitionIdentifier,
                    $latest->definitionVersion,
                );
                $access = $this->recordAccess->plan(
                    $query->context,
                    'business.record.history',
                    $pinned,
                    $scope,
                );
                if (!$access->records->allows($latest->snapshot())) {
                    throw new BusinessRecordNotFound();
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
                $revisionAccess = $this->recordAccess->plan(
                    $query->context,
                    'business.record.history',
                    $pinned,
                    $scope,
                );
                if (!$revisionAccess->records->allows($revision->snapshot())) {
                    throw new BusinessRecordNotFound();
                }
                $views[] = BusinessRecordRevisionView::fromRevision(
                    $revision,
                    $pinned->definition,
                    $revisionAccess->fields,
                );
            }

            return new RecordHistoryResult($views, $hasMore);
        });
    }

    /**
     * Run archive, delete or restore through the one idempotent, fenced path the three share.
     *
     * The operation name is not decoration: it decides which rows are in view when the record is loaded —
     * archive sees only live ones, delete also sees archived ones, restore sees everything — and which
     * domain transition is then applied. Deletion splits again on the definition's soft-delete setting;
     * where it is off, inbound set-null references are cleared before the row is erased, and the record is
     * still marked deleted in memory so the revision and audit entry describe the version the delete
     * produced even though no row survives to carry it.
     *
     * @param   ExecutionContext  $context                 Actor and site the lifecycle change runs as.
     * @param   string            $definitionIdentifier    Definition UUID or handle naming the record type.
     * @param   string            $recordId                Caller-facing identity of the record to move.
     * @param   ?string           $organizationIdentifier  Organization the record is scoped to, or null.
     * @param   int               $expectedVersion         Version the caller read; a mismatch aborts.
     * @param   IdempotencyKey    $key                     Token a retry repeats to replay this outcome.
     * @param   string            $operation               `archive`, `delete` or `restore`; also the suffix
     *          of the idempotency operation name and the label the trail is written under.
     *
     * @return  RecordMutationResult  Keys and new version of the record, with `deleted` raised only for
     *          the delete operation.
     *
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry cannot be replayed.
     * @throws  BusinessRecordTemporarilyUnavailable  When the installation moved between resolve and lock,
     *          the database refused the write transiently, or three idempotency races were lost.
     *
     * @since   2.0.0
     */
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
                    'business.record.' . $operation,
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
                    $this->ownership->remove(
                        AuthorizationResource::item('business_record', $this->recordResourceIdentifier($record)),
                        $context->site(),
                    );
                }
                $this->recordMutation($context, $resolved, $updated, $operation, [], $now);

                return $this->result($updated, $operation, $operation === 'delete');
            },
        );
    }

    /**
     * Clear every inbound set-null reference to a record whose row is about to be erased.
     *
     * A hard delete leaves nothing behind for a foreign key to point at, so the graph has to be emptied
     * first. Every active installed definition on the site is scanned for declared relationships aimed at
     * this one whose target column sits directly on the referencing record's own table; each match is
     * fenced, re-pinned to the version its rows were written under, and unrelated one row at a time so
     * that every source is re-versioned and audited in its own right. What the declared delete behaviour
     * decides is which of three things happens: a cascading relationship is refused outright — whether or
     * not any row currently uses it — because non-owned cascade deletion needs a bounded workflow this
     * path does not provide, a restricting one is detected through a one-row internal integrity probe, and
     * only a set-null one is cleared here. That probe deliberately bypasses actor row disclosure: hidden
     * referrers are never returned to the caller, but neither can they turn a later foreign-key failure into
     * an existence side channel. The sweep is bounded, so a record too widely referenced fails the delete.
     *
     * @param   ExecutionContext            $context         Actor and site the sweep runs as.
     * @param   ResolvedBusinessDefinition  $targetResolved  Pinned definition of the record being deleted.
     * @param   BusinessRecord              $target          Record being deleted, as loaded for the delete.
     * @param   DateTimeImmutable           $now             Instant stamped on every row the sweep rewrites.
     *
     * @return  BusinessRecord  The record being deleted, replaced by the version a write produced when it
     *          held one of the cleared references itself; otherwise the record that was passed in.
     *
     * @throws  BusinessRelationshipRejected  When a matching relationship cascades on delete, or more than
     *          `INBOUND_DELETE_LIMIT` records hold a set-null reference to this one.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When a
     *          referencing definition or the version one of its rows was written under cannot be resolved.
     * @throws  BusinessRecordSchemaUnavailable  When an active installation disagrees with the definition
     *          version it records, or describes no storage for a relationship being cleared.
     * @throws  BusinessRecordNotFound  When a referencing row disappeared between being listed and written.
     * @throws  BusinessRecordVersionConflict  When a referencing row moved between being listed and written.
     * @throws  BusinessRecordTemporarilyUnavailable  When a referencing definition's installation moved
     *          under the sweep, or the database refused a write for a transient reason.
     *
     * @since   2.0.0
     */
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
                if (
                    !in_array(
                        $relationship->onDelete,
                        [DeleteBehavior::Restrict, DeleteBehavior::SetNull],
                        true,
                    )
                ) {
                    continue;
                }
                $generation = $this->mutationFence->lock($context, $candidate->definition->handle);
                $sourceInstalled = $this->definitions->forCreate($context, $candidate->definition->handle);
                $generation->assertMatches($sourceInstalled);
                $sources = $this->reads->referencingForDeleteIntegrity(
                    $sourceInstalled,
                    $target->scope,
                    $relationship,
                    $target->recordKey,
                    $relationship->onDelete === DeleteBehavior::Restrict
                        ? 1
                        : self::INBOUND_DELETE_LIMIT + 1,
                );
                if ($relationship->onDelete === DeleteBehavior::Restrict) {
                    if ($sources !== []) {
                        throw new BusinessRecordReferenceConflict($relationship->handle);
                    }
                    continue;
                }
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
                    if (
                        $source->definitionId === $target->definitionId
                        && hash_equals($source->recordKey, $target->recordKey)
                    ) {
                        $target = $write->source;
                    }
                }
            }
        }

        return $target;
    }

    /**
     * Run one mutation at most once per idempotency key, retrying the races that make that possible.
     *
     * The scope digest binds the key to the site, organization, actor and operation it was presented for,
     * and the entry additionally stores digests of the canonical request and of the caller's authority, so
     * the same key offered for a different request or under different authority is refused rather than
     * replayed. Claiming the entry, running the effect and completing it share the one transaction that
     * also holds the definition's exclusive fence, which is what makes an abandoned command roll its claim
     * back with the work it guarded, and what lets the effect assume the schema cannot move underneath it.
     *
     * Two failures are retried from the top rather than reported. A lost claim race is retried because the
     * next attempt finds the winner's completed entry and replays it; a transient failure is retried
     * because it may not recur. Three attempts is the ceiling for both, after which the operation is
     * reported as temporarily unavailable. Anything else the effect throws propagates unchanged, taking
     * the transaction and the claim with it.
     *
     * @param ExecutionContext $context Actor and site the mutation runs as.
     * @param string $definitionIdentifier Definition UUID or handle fenced for the whole attempt.
     * @param ?string $organizationIdentifier Organization the command is scoped to, or null.
     * @param string $operation Dotted operation name the ledger entry is keyed by.
     * @param IdempotencyKey $key Caller-supplied token identifying this logical command.
     * @param array<string, mixed> $request Canonical request body, fingerprinted so that a
     *          repeat under the same key is proved to be the same request.
     * @param callable(DateTimeImmutable, BusinessRecordMutationGeneration): RecordMutationResult $effect The mutation
     *          to run under the claim; it receives the instant to stamp on every row it writes and
     *          the generation the fence observed.
     *
     * @return  RecordMutationResult  What the effect produced, or the stored result of the earlier command
     *          that already ran under this key, flagged as a replay.
     *
     * @throws  BusinessRecordIdempotencyConflict  When the key was reused for a different request or
     *          authority, has expired, or its stored entry is in progress or fails its checksum.
     * @throws  BusinessRecordTemporarilyUnavailable  When three attempts were lost to idempotency races or
     *          transient failures, or the fence could not be taken.
     *
     * @since   2.0.0
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
        $authenticatedOrganization = $context->organization()?->identifier();
        $scopeDigest = $this->fingerprints->digest([
            'site' => $context->site()->identifier(),
            'organization' => $authenticatedOrganization,
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
                    $authenticatedOrganization,
                ): RecordMutationResult {
                    $generation = $this->mutationFence->lock($context, $definitionIdentifier);
                    $resolved = $this->definitions->forCreate($context, $definitionIdentifier);
                    $generation->assertMatches($resolved);
                    $scope = $this->scope($resolved, $context, $organizationIdentifier);
                    $policyOperation = in_array(
                        $operation,
                        ['business.record.unrelate', 'business.record.reorder'],
                        true,
                    ) ? 'business.record.relate' : $operation;
                    $access = $this->recordAccess->plan($context, $policyOperation, $resolved, $scope);
                    $authorizationFingerprint = $this->fingerprints->digest([
                        'context' => $context->authorizationFingerprint(),
                        'record_access' => $access->digest(),
                    ]);
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
                        $authenticatedOrganization,
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

    /**
     * Reconstitute the outcome of the earlier command that already ran under this idempotency key.
     *
     * Four proofs stand between a stored entry and a replay: it must have been claimed for the same
     * request and the same authority, it must not have expired, it must have completed rather than still
     * be in progress, and its stored result must still match the checksum written beside it. Any of them
     * failing is reported as a conflict, because handing back a result that cannot be proved to describe
     * this command would be worse than refusing it.
     *
     * @param   BusinessRecordIdempotency  $entry                     Stored ledger entry found for this
     *          command's scope digest.
     * @param   string                     $requestFingerprint        Digest of the canonical request now
     *          being presented.
     * @param   string                     $authorizationFingerprint  Digest of the authority the caller
     *          holds now.
     * @param   DateTimeImmutable          $now                       Instant the entry's expiry is
     *          measured against.
     *
     * @return  RecordMutationResult  The stored outcome, rebuilt and flagged as a replay so the caller can
     *          tell it apart from a mutation applied by this call.
     *
     * @throws  BusinessRecordIdempotencyConflict  When the entry describes a different request or
     *          authority or has expired, has not finished, or fails its checksum or cannot be rebuilt.
     *
     * @since   2.0.0
     */
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

    /**
     * Resolve a caller-facing identity to the pinned definition, scope and decoded record behind it.
     *
     * The definition is resolved twice on purpose. The installed version is used first, only to normalize
     * the identity and to learn which definition version the stored row was actually written under; the
     * definition is then resolved again pinned to that version and the scope recomputed against it, and
     * the two scopes must agree before a single value is decoded. The storage key read back with the row
     * must also match the one the identity resolved to, so a row exchanged underneath the lookup is
     * refused rather than returned. An identity the definition cannot parse is reported as not found, not
     * as a validation failure, so a caller cannot probe identities it has no access to.
     *
     * @param   ExecutionContext                   $context                 Actor and site the load runs as.
     * @param   string                             $definitionIdentifier    Definition UUID or handle naming
     *          the record type.
     * @param   string                             $recordId                Caller-facing identity to
     *          resolve, before normalization.
     * @param   ?string                            $organizationIdentifier  Organization the row must
     *          belong to, or null for a definition that carries no organization scope.
     * @param   string                             $operation               Operation whose row and field
     *          policy must admit the record.
     * @param   bool                               $includeArchived         True to also reach an archived
     *          row.
     * @param   bool                               $includeDeleted          True to also reach a
     *          soft-deleted row.
     * @param   ?BusinessRecordMutationGeneration  $generation              Generation the caller's fence
     *          observed, asserted against the resolved definition; null when the caller holds no fence.
     *
     * @return  array{ResolvedBusinessDefinition, RecordScope, BusinessRecord, BusinessRecordAccessPlan}
     *          The pinned definition, exact scope, decoded record and operation-specific access decision.
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When no
     *          definition on this site matches the identifier, or the pinned version is not published.
     * @throws  BusinessRecordSchemaUnavailable  When the schema is not installed and active, or a stored
     *          value does not match the column the blueprint describes.
     * @throws  BusinessRecordValidationFailed  When the organization scope is not one this definition
     *          accepts.
     * @throws  BusinessRecordNotFound  When the identity is not usable for this definition, or no row it
     *          admits answers it in this scope.
     * @throws  BusinessRecordReferenceConflict  When the definition declares no identity field, the pinned
     *          definition resolves to a different scope, or the row's storage key disagrees with the
     *          identity that addressed it.
     * @throws  BusinessRecordTemporarilyUnavailable  When the resolved installation is not the generation
     *          the caller's fence is holding.
     *
     * @since   2.0.0
     */
    private function load(
        ExecutionContext $context,
        string $definitionIdentifier,
        string $recordId,
        ?string $organizationIdentifier,
        string $operation,
        bool $includeArchived = false,
        bool $includeDeleted = false,
        ?BusinessRecordMutationGeneration $generation = null,
    ): array {
        $installed = $this->definitions->forCreate($context, $definitionIdentifier);
        $generation?->assertMatches($installed);
        $scope = $this->scope($installed, $context, $organizationIdentifier);
        $installedAccess = $this->recordAccess->plan($context, $operation, $installed, $scope);
        try {
            $normalizedId = $this->values->identity(
                $installed->definition,
                [$this->identityField($installed->definition)->handle => $recordId],
                null,
            );
        } catch (InvalidArgumentException) {
            throw new BusinessRecordNotFound();
        }
        $identity = $this->reads->identity($installed, $scope, $installedAccess, $normalizedId, $includeDeleted)
            ?? throw new BusinessRecordNotFound();
        $resolved = $this->definitions->pinned($context, $definitionIdentifier, $identity->definitionVersion);
        $pinnedScope = $this->scope($resolved, $context, $organizationIdentifier);
        if ($pinnedScope->toArray() !== $scope->toArray()) {
            throw new BusinessRecordReferenceConflict();
        }
        $access = $this->recordAccess->plan($context, $operation, $resolved, $pinnedScope);
        $record = $this->reads->get(
            $resolved,
            $pinnedScope,
            $access,
            $normalizedId,
            $includeArchived,
            $includeDeleted,
        ) ?? throw new BusinessRecordNotFound();
        if (!hash_equals($record->recordKey, $identity->recordKey)) {
            throw new BusinessRecordReferenceConflict();
        }
        if (!$access->records->allows($record->values())) {
            throw new BusinessRecordNotFound();
        }

        return [$resolved, $pinnedScope, $record, $access];
    }

    /**
     * Derive the scope an operation runs in, reporting a bad organization as a validation failure.
     *
     * The site half comes from the execution context and is never caller-supplied; only the organization
     * is, and the definition's scope mode decides whether it is required, rejected or ignored. Translating
     * the domain's argument error into a violation on the `scope` field is what lets a delivery adapter
     * report a wrong organization beside a form's other field problems instead of as a request fault.
     *
     * @param   ResolvedBusinessDefinition  $resolved                Definition whose scope mode applies.
     * @param   ExecutionContext            $context                 Actor and site the operation runs as.
     * @param   ?string                     $organizationIdentifier  Organization the caller asked to work
     *          in, or null.
     *
     * @return  RecordScope  Site and organization every read and write of this operation is confined to.
     *
     * @throws  BusinessRecordValidationFailed  Carrying one violation on `scope`, when the organization is
     *          malformed, missing for a mode that requires it, or supplied for a mode that does not.
     *
     * @since   2.0.0
     */
    private function scope(
        ResolvedBusinessDefinition $resolved,
        ExecutionContext $context,
        ?string $organizationIdentifier,
    ): RecordScope {
        try {
            $organizationScoped = in_array(
                $resolved->definition->scope,
                [ScopeMode::Organization, ScopeMode::SiteOrganization],
                true,
            );
            $authenticatedOrganization = $context->organization()?->identifier();
            if (
                ($organizationScoped && $authenticatedOrganization === null)
                || ($organizationScoped
                    && $organizationIdentifier !== null
                    && $organizationIdentifier !== $authenticatedOrganization)
                || (!$organizationScoped && $organizationIdentifier !== null)
            ) {
                throw new InvalidArgumentException(
                    'The request does not match its authenticated organization context.',
                );
            }
            return RecordScope::forDefinition(
                $resolved->definition->scope,
                $context->site(),
                $organizationScoped ? $authenticatedOrganization : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw new BusinessRecordValidationFailed([
                new ValidationViolation('scope', 'scope', $exception->getMessage()),
            ]);
        }
    }

    /**
     * Exchange entity-reference values for the internal storage key each one points at.
     *
     * Only the handles named are considered, which is what keeps an update from re-resolving references it
     * never touched. Each target is fenced, resolved, and looked up in the same organization as the record
     * being written, so a reference can never be made to cross a scope boundary; a target that is missing,
     * out of scope, or whose field declares no usable target is reported as a violation on that field
     * rather than aborting the pass, so every bad reference in a submission is reported at once.
     *
     * @param   ExecutionContext      $context     Actor and site the resolution runs as.
     * @param   EntityTypeDefinition  $definition  Definition whose fields are inspected for references.
     * @param   RecordScope           $scope       Scope the source record belongs to, which every target
     *          must share.
     * @param   array<string, mixed>  $values      Value set to rewrite, keyed by field handle.
     * @param   list<string>          $handles     Handles this pass is allowed to touch; anything outside
     *          it is left exactly as it arrived.
     *
     * @return  array<string, mixed>  The same value set with each named reference replaced by the target's
     *          internal record key, ready to be written to the column.
     *
     * @throws  BusinessRecordValidationFailed  Carrying one violation per unusable reference, when a value
     *          or its declared target is not a string, or names no record in this scope; and carrying a
     *          `scope` violation when a target definition will not accept the source's organization.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When a
     *          field's declared target definition does not exist on this site, or its owner is disabled.
     * @throws  BusinessRecordSchemaUnavailable  When a target definition's schema is not installed and
     *          active, or a stored identity is malformed.
     * @throws  BusinessRecordTemporarilyUnavailable  When a target's installation moved between its
     *          resolve and its lock.
     *
     * @since   2.0.0
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
                $targetAccess = $this->recordAccess->plan(
                    $context,
                    'business.record.read',
                    $target,
                    $targetScope,
                );
                $targetId = $this->values->identity(
                    $target->definition,
                    [$this->identityField($target->definition)->handle => $value],
                    null,
                );
                $identity = $this->reads->identity($target, $targetScope, $targetAccess, $targetId);
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

    /**
     * Reject a submitted field set without revealing which handle was absent or forbidden.
     *
     * @param   BusinessRecordAccessPlan  $access  Authoritative field-use decision for the operation.
     * @param   FieldAccessUsage          $usage   Create or update use being attempted.
     * @param   list<string>              $fields  Caller-submitted handles.
     *
     * @return  void
     *
     * @throws  BusinessRecordValidationFailed  With one generic violation when any handle is unavailable.
     *
     * @since   2.0.0
     */
    private function assertFieldInput(
        BusinessRecordAccessPlan $access,
        FieldAccessUsage $usage,
        array $fields,
    ): void {
        foreach ($fields as $field) {
            if (!$access->fields->allows($usage, $field)) {
                throw new BusinessRecordValidationFailed([
                    new ValidationViolation('record', 'field_access', 'One or more submitted fields are unavailable.'),
                ]);
            }
        }
    }

    /**
     * Refuse the mutation unless the record still sits at the version the caller read.
     *
     * @param   BusinessRecord  $record           Record as it was just loaded.
     * @param   int             $expectedVersion  Version the caller submitted the mutation against.
     *
     * @return  void
     *
     * @throws  BusinessRecordVersionConflict  When the stored record moved past the expected version.
     *
     * @since   2.0.0
     */
    private function expected(BusinessRecord $record, int $expectedVersion): void
    {
        if ($record->version !== $expectedVersion) {
            throw new BusinessRecordVersionConflict($expectedVersion, $record->version);
        }
    }

    /**
     * Ask the gateway whether the actor may perform one record operation at all.
     *
     * The question is put against the `business_record` collection rather than an individual record, so it
     * is settled before any definition is resolved or any row is read — a caller without the capability
     * never learns whether the record it named exists. An action's own capability and that of the workflow
     * transition it performs are asked for separately, by `action()`, once the record itself is in hand.
     *
     * @param   ExecutionContext  $context     Actor, site and authority the operation runs under.
     * @param   string            $capability  Dotted capability the operation requires, such as
     *          `business.record.update`.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When policy refuses the actor this
     *          operation on business records in this site.
     *
     * @since   2.0.0
     */
    private function authorize(ExecutionContext $context, string $capability): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString($capability),
            AuthorizationResource::collection('business_record'),
        );
    }

    /**
     * Describe a stored record as the outcome both the caller and the idempotency ledger receive.
     *
     * The same object is returned to the caller and checksummed into the ledger entry, which is what makes
     * a later replay answerable without re-reading the record.
     *
     * @param   BusinessRecord  $record     Record at the version this mutation left it.
     * @param   string          $operation  Name the mutation is reported under, matching the label used in
     *          the revision and the audit entry.
     * @param   bool            $deleted    True when no live row survives the mutation.
     *
     * @return  RecordMutationResult  Identity, version and workflow state of the record, marked as applied
     *          rather than replayed.
     *
     * @since   2.0.0
     */
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

    /**
     * Write the revision and the audit entry that describe one applied mutation.
     *
     * Both are written inside the mutation's own transaction, so a rolled-back write takes its trail with
     * it. The revision holds a canonical snapshot of the stored values — virtual computed fields are left
     * out because nothing about them is stored — and relationship evidence is folded into that snapshot
     * under a reserved key the definition is not allowed to collide with. A definition with revisions
     * turned off gets no snapshot at all, but is still audited: the audit entry never carries values, only
     * the changed handles with the restricted and secret ones marked as redacted, and it stands in for the
     * record's identity with a keyed digest so the trail discloses nothing the record itself protects.
     *
     * @param   ExecutionContext            $context        Actor, site and request the mutation ran under.
     * @param   ResolvedBusinessDefinition  $resolved       Definition the record was written against,
     *          supplying each field's computation mode and sensitivity.
     * @param   BusinessRecord              $record         Record at the version this mutation produced.
     * @param   string                      $operation      Label the entry is recorded under, such as
     *          `update` or `relate.lines`; the audit action prefixes it with `business.record.`.
     * @param   list<string>                $changedFields  Handles whose value the mutation changed;
     *          sorted in place here so the trail is order-independent.
     * @param   DateTimeImmutable           $now            Instant stamped on both entries.
     * @param   array<string, mixed>        $evidence       Relationship details to preserve alongside the
     *          snapshot; empty for a mutation that touched no relationship.
     *
     * @return  void
     *
     * @throws  BusinessRecordSchemaUnavailable  When the definition declares a field whose handle collides
     *          with the reserved key relationship evidence is stored under.
     *
     * @since   2.0.0
     */
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

    /**
     * Build the disclosure-safe evidence a relationship mutation records about its far end.
     *
     * The target's identity and any embedded line values are reduced to keyed digests rather than stored,
     * so a later request can be compared against what was recorded without the trail holding the values
     * themselves — which matters because a line's values are ordinary record data and its identity may be
     * a business reference.
     *
     * @param   string                $relationship    Handle of the relationship that was written.
     * @param   string                $targetIdentity  Caller-facing identity of the far end, digested
     *          rather than stored.
     * @param   ?int                  $position        Slot the caller asked for in an ordered collection,
     *          or null when it appended or the relationship is unordered.
     * @param   array<string, mixed>  $embeddedValues  Values of an owned line created by the same write;
     *          empty for every other kind of relationship.
     *
     * @return  array<string, mixed>  The relationship handle, the digest of the target identity, the
     *          requested position, and the digest of the embedded values or null when there were none.
     *
     * @since   2.0.0
     */
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

    /**
     * Reduce a record's values to the canonical snapshot its revision stores.
     *
     * Virtual computed fields are skipped because nothing about them is stored to begin with, and a field
     * the record does not carry is left out rather than written as null, so absence in a snapshot means
     * the row held no such column. Every surviving value is canonicalized, which is what makes two
     * snapshots comparable byte for byte across processes and definition versions.
     *
     * @param   EntityTypeDefinition  $definition  Definition supplying the field list and each field's
     *          computation mode.
     * @param   BusinessRecord        $record      Record whose values are being snapshotted.
     *
     * @return  array<string, mixed>  Stored field values keyed by handle, in their canonical storage
     *          spelling; empty when the record carries nothing worth preserving.
     *
     * @since   2.0.0
     */
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

    /**
     * Name the field handles whose value actually differs between two value sets.
     *
     * Comparison is made on the canonical spelling rather than the raw value, so resubmitting a decimal or
     * a timestamp in another equivalent form does not register as a change and does not appear in the
     * trail. A handle present on only one side is compared against null, which means a field that went
     * from absent to explicitly null counts as unchanged.
     *
     * @param   array<string, mixed>  $before  Values the record held before the mutation.
     * @param   array<string, mixed>  $after   Values it holds afterwards.
     *
     * @return  list<string>  Changed handles in ascending string order; empty when the two sets are
     *          equivalent, which is what a write with no effect produces.
     *
     * @since   2.0.0
     */
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

    /**
     * Find the field that carries a definition's caller-facing identity.
     *
     * Which field that is follows from the identity strategy rather than from a name: a UUID-identity type
     * declares a `core.uuid` field, and one identified by a business reference declares a
     * `core.reference_identity` field. A definition that declares neither cannot address its own records,
     * so this refuses rather than guessing at the first plausible field.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose identity field is wanted.
     *
     * @return  FieldDefinition  The first field of the type the identity strategy requires.
     *
     * @throws  BusinessRecordReferenceConflict  When the definition declares no field of that type.
     *
     * @since   2.0.0
     */
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

    /**
     * Look a field up by handle without insisting that the definition still declares it.
     *
     * Used while annotating an audit entry, where a handle that changed under an older definition version
     * may have been dropped since. Not finding it costs the entry its redaction marking, which is why the
     * caller treats an unknown handle as not sensitive rather than as an error.
     *
     * @param   EntityTypeDefinition  $definition  Definition to search.
     * @param   string                $handle      Field handle to look for.
     *
     * @return  ?FieldDefinition  The field, or null when this definition version declares none under that
     *          handle.
     *
     * @since   2.0.0
     */
    private function optionalField(EntityTypeDefinition $definition, string $handle): ?FieldDefinition
    {
        foreach ($definition->fields() as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Resolve a relationship handle against the definition version the record is pinned to.
     *
     * Resolution goes through the runtime lookup, so a legacy ordered-lines field answers here as the
     * owned collection it behaves like and relationship commands need not distinguish the two. Because the
     * pinned version is what is searched, a relationship added since the record was written is correctly
     * reported as undeclared for that record.
     *
     * @param   EntityTypeDefinition  $definition  Pinned definition the record was written under.
     * @param   string                $handle      Relationship or ordered-lines handle the command named.
     *
     * @return  RelationshipDefinition  The declared association, or the one synthesized for an
     *          ordered-lines field.
     *
     * @throws  BusinessRelationshipRejected  When the pinned definition declares neither a relationship
     *          nor an ordered-lines field under that handle.
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When a matching
     *          ordered-lines field declares no usable target entity.
     *
     * @since   2.0.0
     */
    private function relationship(EntityTypeDefinition $definition, string $handle): RelationshipDefinition
    {
        return $definition->runtimeRelationship($handle)
            ?? throw new BusinessRelationshipRejected('The relationship is not declared by the pinned definition.');
    }

    /**
     * Rebuild the non-transferable binding shared by approval request and action execution.
     *
     * @param   ExecuteRecordActionCommand  $command  Validated action attempt.
     * @param   BusinessRecord              $record   Policy-visible current record version.
     * @param   ActionDefinition            $action   Immutable definition-declared action.
     *
     * @return  ApprovalBinding  Exact actor, scope, action, record, version, and payload binding.
     *
     * @since   2.0.0
     */
    private function actionApprovalBinding(
        ExecuteRecordActionCommand $command,
        BusinessRecord $record,
        ActionDefinition $action,
    ): ApprovalBinding {
        return ApprovalBinding::fromContext(
            $command->context,
            'business.record.action:' . $action->handle,
            'business_record',
            $this->recordResourceIdentifier($record),
            $record->version,
            $this->fingerprints->digest([
                'definition_id' => $record->definitionId,
                'definition_version' => $record->definitionVersion,
                'record_key' => $record->recordKey,
                'record_id' => $record->recordId,
                'record_version' => $record->version,
                'action' => $action->handle,
                'input' => $command->input,
            ]),
        );
    }

    /**
     * Build the collision-safe authorization identity shared by ownership and record approvals.
     *
     * Record keys are unique only within a definition's physical table, so a bare UUID cannot identify
     * one resource across the site-wide ownership registry. Pairing the immutable definition UUID with
     * the storage key keeps two definitions free to carry the same caller-selected UUID record key.
     *
     * @param   BusinessRecord  $record  Record whose authorization resource is addressed.
     *
     * @return  string  `<definition UUID>:<record key UUID>`.
     *
     * @since   2.0.0
     */
    private function recordResourceIdentifier(BusinessRecord $record): string
    {
        return $record->definitionId . ':' . $record->recordKey;
    }

    /**
     * Find the action a command named among those the pinned definition declares.
     *
     * Searching the pinned version rather than the installed one means an action introduced after a record
     * was written cannot be run against it until that record is brought forward.
     *
     * @param   EntityTypeDefinition  $definition  Pinned definition the record was written under.
     * @param   string                $handle      Action handle the command named.
     *
     * @return  ActionDefinition  The declared action, carrying its capability, condition and transition.
     *
     * @throws  BusinessRecordActionRejected  When the pinned definition declares no action under that
     *          handle.
     *
     * @since   2.0.0
     */
    private function actionDefinition(EntityTypeDefinition $definition, string $handle): ActionDefinition
    {
        foreach ($definition->actions() as $action) {
            if ($action->handle === $handle) {
                return $action;
            }
        }
        throw new BusinessRecordActionRejected('The action is not declared by the pinned definition.');
    }

    /**
     * Resolve the pinned definition of the line type an owned-line collection stores.
     *
     * The version used is not the installed one. It is read off the owner's own installed blueprint, which
     * records the line definition version its line table was generated for, and the line definition is
     * then fenced and pinned to exactly that — so the columns being written always match the table that
     * exists, even after the line type has been published again. A blueprint that carries no such table,
     * or records no usable version for it, is a schema the owner cannot store lines through at all.
     *
     * @param   ExecutionContext            $context       Actor and site the resolution runs as.
     * @param   ResolvedBusinessDefinition  $owner         Owner definition whose blueprint names the line
     *          table.
     * @param   RelationshipDefinition      $relationship  Owned-line relationship being written.
     *
     * @return  ResolvedBusinessDefinition  The line type at the version its table was generated for,
     *          paired with its own installation.
     *
     * @throws  BusinessRelationshipRejected  When the owner's blueprint carries no table for this
     *          collection, or records no integer target definition version for it.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable  When
     *          the line type does not exist on this site, or that version of it is not published.
     * @throws  BusinessRecordSchemaUnavailable  When the line type's schema is not installed and active.
     * @throws  BusinessRecordTemporarilyUnavailable  When the line type's installation moved between the
     *          lock and the resolve.
     *
     * @since   2.0.0
     */
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

    /**
     * Refuse a link whose two ends do not sit in the same site and organization.
     *
     * Scope isolation is enforced here rather than by the storage, because two records of different types
     * can be perfectly valid rows and still belong to tenants that must not be joined.
     *
     * @param   BusinessRecord  $source  Record the relationship is declared on.
     * @param   BusinessRecord  $target  Record being linked to it.
     *
     * @return  void
     *
     * @throws  BusinessRecordReferenceConflict  When the two records' scopes differ in any dimension.
     *
     * @since   2.0.0
     */
    private function sameScope(BusinessRecord $source, BusinessRecord $target): void
    {
        if ($source->scope->toArray() !== $target->scope->toArray()) {
            throw new BusinessRecordReferenceConflict();
        }
    }

    /**
     * Flatten a record's values into the scalar map an action condition is evaluated against.
     *
     * Exact decimals become their canonical base-10 literal and timestamps an ISO-8601 string with
     * microseconds and offset, so a condition compares text and never a float. Everything else that is not
     * already a scalar — money, quantities, arrays, embedded values, encrypted envelopes — is presented as
     * null, which means a precondition can test such a field for absence but can never branch on its
     * content.
     *
     * @param   BusinessRecord  $record  Record the condition is being evaluated against.
     *
     * @return  array<string, scalar|null>  Every field handle the record carries, mapped to a scalar or to
     *          null where the value has no scalar spelling.
     *
     * @since   2.0.0
     */
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
