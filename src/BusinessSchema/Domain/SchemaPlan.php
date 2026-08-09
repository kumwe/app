<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use DateTimeImmutable;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;

/**
 * One approvable, executable migration of a business definition's physical schema on one site.
 *
 * A plan is the unit the whole schema pipeline is built around: the planner compiles the difference between
 * two definition versions into an ordered set of `SchemaOperation` steps, an administrator approves that
 * exact content, and only then may an executor run DDL. Everything needed to make those three stages safe
 * across processes lives here — the definition versions and checksums the plan moves between, the physical
 * schema checksum it expects to find and the one it must arrive at, the approval evidence, the recovery
 * evidence a locking or destructive plan requires, the monotonic execution fence, and the recorded outcome.
 *
 * The plan is immutable and content addressed. `checksum()` covers the operations and the bindings but not
 * the lifecycle state, so re-planning invalidates an existing approval while a status change does not; the
 * constructor refuses an approval whose checksum is not this plan's. Every transition returns a new instance
 * with an incremented revision, and the constructor re-proves the whole state machine, so a plan loaded from
 * the database cannot carry an evidence combination the transitions would never have produced.
 *
 * @since  2.0.0
 */
final readonly class SchemaPlan
{
    /**
     * Steps in the order the executor must apply them, with contiguous ordinals starting at one.
     *
     * @var    list<SchemaOperation>
     * @since  2.0.0
     */
    private array $operations;

    /**
     * Execution result recorded when the plan reached a terminal status, or null while it has not.
     *
     * A failing plan gets the caller's outcome plus the `error_code` entry `fail()` and
     * `recoveryRequired()` add, which is how an operator learns why execution stopped.
     *
     * @var    array<string, mixed>|null
     * @since  2.0.0
     */
    public ?array $outcome;

    /**
     * Instant of the most recent transition, equal to the creation time for a plan that has had none.
     *
     * @var    DateTimeImmutable
     * @since  2.0.0
     */
    public DateTimeImmutable $updatedAt;

    /**
     * Assemble a plan and prove its bindings, ordering, risk, and lifecycle evidence all agree.
     *
     * @param   string                     $id                      UUID identifying this plan.
     * @param   string                     $definitionId            UUID of the definition being changed.
     * @param   string                     $siteIdentifier          Site whose installed tables it acts on.
     * @param   int|null                   $fromDefinitionVersion   Version upgraded from, null on first install.
     * @param   int                        $toDefinitionVersion     Version installed; above the prior one.
     * @param   string|null                $fromDefinitionChecksum  SHA-256 of the prior definition version.
     * @param   string                     $toDefinitionChecksum    SHA-256 of the definition being installed.
     * @param   string|null                $fromSchemaChecksum      Physical schema the plan expects to find.
     * @param   string                     $targetSchemaChecksum    Physical schema it must arrive at.
     * @param   list<SchemaOperation>      $operations              Steps in any order; sorted by ordinal here.
     * @param   SchemaRisk                 $risk                    Impact class; the highest a step declares.
     * @param   SchemaPlanStatus           $status                  Lifecycle position the evidence must fit.
     * @param   int                        $revision                Optimistic-locking revision, from one.
     * @param   string                     $createdBy               Identity of the actor who planned it.
     * @param   DateTimeImmutable          $createdAt               Instant the plan was compiled.
     * @param   SchemaPlanApproval|null    $approval                Approval bound to this plan's checksum.
     * @param   string|null                $recoveryEvidenceId      UUID of the backing restore drill.
     * @param   int|null                   $executionFence          Fence held by the executor running it.
     * @param   array<string, mixed>|null  $outcome                 Result; only a terminal status has one.
     * @param   DateTimeImmutable|null     $updatedAt               Transition time; defaults to $createdAt.
     *
     * @throws  InvalidBusinessSchema  When an identifier or checksum is malformed, the version bounds are not
     *          ascending, the prior version and checksum are not both present or both
     *          absent, more than 10000 operations are supplied, their ordinals are not
     *          contiguous from one, the risk is not the highest one declared, the
     *          revision is below one, the fence is below one, the outcome is not a
     *          string-keyed object, the status carries evidence it may not or omits
     *          evidence it must, the approval is bound to a different canonical plan,
     *          or the update time precedes the creation time.
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the outcome holds a value
     *          that cannot be canonically encoded, such as a float or an object, or an approved plan carries
     *          more than 512 operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public string $definitionId,
        public string $siteIdentifier,
        public ?int $fromDefinitionVersion,
        public int $toDefinitionVersion,
        public ?string $fromDefinitionChecksum,
        public string $toDefinitionChecksum,
        public ?string $fromSchemaChecksum,
        public string $targetSchemaChecksum,
        array $operations,
        public SchemaRisk $risk,
        public SchemaPlanStatus $status,
        public int $revision,
        public string $createdBy,
        public DateTimeImmutable $createdAt,
        public ?SchemaPlanApproval $approval = null,
        public ?string $recoveryEvidenceId = null,
        public ?int $executionFence = null,
        ?array $outcome = null,
        ?DateTimeImmutable $updatedAt = null,
    ) {
        SchemaDocument::assertUuid($id, 'The schema-plan ID');
        SchemaDocument::assertUuid($definitionId, 'The schema-plan definition ID');
        SchemaDocument::assertIdentifier($siteIdentifier, 'The schema-plan site');
        if (
            $toDefinitionVersion < 1
            || ($fromDefinitionVersion !== null && $fromDefinitionVersion < 1)
            || ($fromDefinitionVersion !== null && $toDefinitionVersion <= $fromDefinitionVersion)
        ) {
            throw new InvalidBusinessSchema('A schema plan has invalid definition version bounds.');
        }
        if (($fromDefinitionVersion === null) !== ($fromDefinitionChecksum === null)) {
            throw new InvalidBusinessSchema('A schema plan must pair its prior definition version and checksum.');
        }
        SchemaDocument::assertChecksum($fromDefinitionChecksum, 'The prior definition checksum', true);
        SchemaDocument::assertChecksum($toDefinitionChecksum, 'The target definition checksum');
        SchemaDocument::assertChecksum($fromSchemaChecksum, 'The prior physical schema checksum', true);
        SchemaDocument::assertChecksum($targetSchemaChecksum, 'The target physical schema checksum');
        if (count($operations) > 10_000) {
            throw new InvalidBusinessSchema('A schema plan contains too many operations.');
        }
        usort($operations, static fn (SchemaOperation $left, SchemaOperation $right): int =>
            $left->ordinal <=> $right->ordinal);
        foreach ($operations as $offset => $operation) {
            if ($operation->ordinal !== $offset + 1) {
                throw new InvalidBusinessSchema(
                    'Schema-plan operations must have contiguous ordinals starting at one.',
                );
            }
        }
        $calculatedRisk = SchemaRisk::highest(array_map(
            static fn (SchemaOperation $operation): SchemaRisk => $operation->risk,
            $operations,
        ));
        if ($risk !== $calculatedRisk) {
            throw new InvalidBusinessSchema('A schema plan risk must equal its highest operation risk.');
        }
        if ($revision < 1) {
            throw new InvalidBusinessSchema('A schema-plan persistence revision must be positive.');
        }
        SchemaDocument::assertBoundedText($createdBy, 'The schema-plan creator');
        SchemaDocument::assertUuid($recoveryEvidenceId ?? $id, 'The schema-plan recovery-evidence ID');
        if ($executionFence !== null && $executionFence < 1) {
            throw new InvalidBusinessSchema('A schema-plan execution fence must be positive.');
        }
        if ($outcome !== null) {
            SchemaDocument::assertObjectValue($outcome, 'A schema-plan outcome');
            CanonicalDefinitionJson::encode($outcome);
        }
        $this->assertState($status, $approval, $recoveryEvidenceId, $executionFence, $outcome);
        if ($approval !== null && !hash_equals($approval->approvedChecksum, $this->checksumFor($operations))) {
            throw new InvalidBusinessSchema('A schema-plan approval is bound to a different canonical plan.');
        }
        $this->operations = $operations;
        $this->outcome = $outcome;
        $this->updatedAt = $updatedAt ?? $createdAt;
        if ($this->updatedAt < $createdAt) {
            throw new InvalidBusinessSchema('A schema plan cannot be updated before it is created.');
        }
    }

    /**
     * Rebuild a plan from its persisted document and confirm it was not tampered with.
     *
     * When the stored document carries a `plan_checksum`, it is compared against the checksum recomputed
     * from the decoded content, so an edited plan row is refused rather than approved or executed.
     *
     * @param   array<string, mixed>  $document  Stored plan object, as written by `toArray()`.
     *
     * @return  self  The revalidated plan, with both timestamps normalized to UTC.
     *
     * @throws  InvalidBusinessSchema  When the document carries an unknown property, a field is missing or
     *          misshapen, the stored risk or status is not a known one, any plan
     *          invariant fails, or the stored checksum does not match the content.
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the stored outcome holds
     *          a value that cannot be canonically encoded, or the document holds more than 512 operations.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly(
            $document,
            [
                'id', 'definition_id', 'site_identifier', 'from_definition_version', 'to_definition_version',
                'from_definition_checksum', 'to_definition_checksum', 'from_schema_checksum',
                'target_schema_checksum', 'operations', 'risk', 'status', 'revision', 'created_by', 'created_at',
                'approval', 'recovery_evidence_id', 'execution_fence', 'outcome', 'updated_at', 'plan_checksum',
            ],
            'A schema plan',
        );
        $risk = SchemaRisk::tryFrom(SchemaDocument::string($document, 'risk'))
            ?? throw new InvalidBusinessSchema('A schema plan risk is invalid.');
        $status = SchemaPlanStatus::tryFrom(SchemaDocument::string($document, 'status'))
            ?? throw new InvalidBusinessSchema('A schema plan status is invalid.');
        $approval = SchemaDocument::object($document, 'approval', true);
        $plan = new self(
            SchemaDocument::string($document, 'id'),
            SchemaDocument::string($document, 'definition_id'),
            SchemaDocument::string($document, 'site_identifier'),
            SchemaDocument::nullableInteger($document, 'from_definition_version'),
            SchemaDocument::integer($document, 'to_definition_version'),
            SchemaDocument::nullableString($document, 'from_definition_checksum'),
            SchemaDocument::string($document, 'to_definition_checksum'),
            SchemaDocument::nullableString($document, 'from_schema_checksum'),
            SchemaDocument::string($document, 'target_schema_checksum'),
            array_map(SchemaOperation::fromArray(...), SchemaDocument::objects($document, 'operations')),
            $risk,
            $status,
            SchemaDocument::integer($document, 'revision'),
            SchemaDocument::string($document, 'created_by'),
            SchemaDocument::date(SchemaDocument::string($document, 'created_at'), 'The schema-plan creation time'),
            $approval === null ? null : SchemaPlanApproval::fromArray($approval),
            SchemaDocument::nullableString($document, 'recovery_evidence_id'),
            SchemaDocument::nullableInteger($document, 'execution_fence'),
            SchemaDocument::object($document, 'outcome', true),
            SchemaDocument::date(SchemaDocument::string($document, 'updated_at'), 'The schema-plan update time'),
        );
        $checksum = $document['plan_checksum'] ?? null;
        if ($checksum !== null && (!is_string($checksum) || !hash_equals($plan->checksum(), $checksum))) {
            throw new InvalidBusinessSchema('A persisted schema-plan checksum does not match its canonical plan.');
        }

        return $plan;
    }

    /**
     * List the steps the executor walks.
     *
     * @return  list<SchemaOperation>  The operations in ordinal order, so a step's position in this list is
     *          one less than its ordinal.
     *
     * @since   2.0.0
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * Bind approval evidence to this exact plan so an executor may run it.
     *
     * The caller passes back the checksum it showed the approver, and approval is refused unless it still
     * matches; that is what stops a plan re-compiled between inspection and approval from inheriting
     * someone's consent. Risk decides what else the approver had to supply: every class but an online-safe
     * addition needs a step-up confirmation, and a locking or destructive one needs a restore drill.
     *
     * @param   string             $actorIdentifier     Bounded identity of the approving administrator.
     * @param   DateTimeImmutable  $approvedAt          Instant of approval, also recorded as the update time.
     * @param   string             $expectedChecksum    Plan checksum the approver was shown.
     * @param   string|null        $confirmationDigest  Step-up confirmation, required above online-safe additive.
     * @param   string|null        $recoveryEvidenceId  Restore-drill UUID, required for locking and destructive
     *          plans.
     *
     * @return  self  An approved copy at the next revision, carrying the new approval evidence.
     *
     * @throws  InvalidBusinessSchema  When the plan is not pending approval, the checksum no longer matches,
     *          a required confirmation digest or recovery evidence is absent, or the
     *          approval evidence itself is malformed.
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the plan holds more than
     *          512 operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    public function approve(
        string $actorIdentifier,
        DateTimeImmutable $approvedAt,
        string $expectedChecksum,
        ?string $confirmationDigest = null,
        ?string $recoveryEvidenceId = null,
    ): self {
        if ($this->status !== SchemaPlanStatus::PendingApproval) {
            throw new InvalidBusinessSchema('Only a pending schema plan can be approved.');
        }
        if (!hash_equals($this->checksum(), $expectedChecksum)) {
            throw new InvalidBusinessSchema('The schema plan changed after it was inspected.');
        }
        if ($this->risk->requiresHighImpactAuthorization() && $confirmationDigest === null) {
            throw new InvalidBusinessSchema('A high-impact schema plan requires a confirmation digest.');
        }
        if ($this->risk->requiresRecoveryEvidence() && $recoveryEvidenceId === null) {
            throw new InvalidBusinessSchema('A locking or destructive schema plan requires recovery evidence.');
        }

        return $this->withState(
            SchemaPlanStatus::Approved,
            $this->revision + 1,
            new SchemaPlanApproval($actorIdentifier, $approvedAt, $expectedChecksum, $confirmationDigest),
            $recoveryEvidenceId,
            null,
            null,
            $approvedAt,
        );
    }

    /**
     * Take an approved plan into execution under the fence of the lock the executor holds.
     *
     * The fence is recorded on the plan so writes can be gated on it: the plan repository matches the stored
     * fence when replacing a row, so an executor whose lock was taken over fails its write rather than
     * overwriting the newer owner's work.
     *
     * @param   int                $fence  Monotonic fence issued with the definition lock; must be positive.
     * @param   DateTimeImmutable  $at     Instant to record as the update time.
     *
     * @return  self  An executing copy at the next revision, holding the fence and no outcome.
     *
     * @throws  InvalidBusinessSchema  When the plan is not approved, or the fence is below one.
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the plan holds more than
     *          512 operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    public function begin(int $fence, DateTimeImmutable $at): self
    {
        if ($this->status !== SchemaPlanStatus::Approved || $fence < 1) {
            throw new InvalidBusinessSchema('Only an approved schema plan can begin under a positive fence.');
        }

        return $this->withState(
            SchemaPlanStatus::Executing,
            $this->revision + 1,
            $this->approval,
            $this->recoveryEvidenceId,
            $fence,
            null,
            $at,
        );
    }

    /**
     * Put an interrupted plan back into execution under a freshly issued fence.
     *
     * This is the recovery counterpart to `begin()`: it accepts a plan that is still marked executing after
     * a crash, as well as one that stopped on a failure or was held for operator inspection, and clears the
     * recorded outcome so the resumed run records its own. The new fence supersedes the abandoned one.
     *
     * @param   int                $fence  Monotonic fence issued with the definition lock; must be positive.
     * @param   DateTimeImmutable  $at     Instant to record as the update time.
     *
     * @return  self  An executing copy at the next revision, holding the new fence and no outcome.
     *
     * @throws  InvalidBusinessSchema  When the plan is not executing, failed, or awaiting recovery, or the
     *          fence is below one.
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the plan holds more than
     *          512 operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    public function resume(int $fence, DateTimeImmutable $at): self
    {
        if (
            !in_array(
                $this->status,
                [SchemaPlanStatus::Executing, SchemaPlanStatus::Failed, SchemaPlanStatus::RecoveryRequired],
                true,
            ) || $fence < 1
        ) {
            throw new InvalidBusinessSchema('Only an interrupted schema plan can resume under a positive fence.');
        }

        return $this->withState(
            SchemaPlanStatus::Executing,
            $this->revision + 1,
            $this->approval,
            $this->recoveryEvidenceId,
            $fence,
            null,
            $at,
        );
    }

    /**
     * Settle an executing plan as completed and store the executor's report of the run.
     *
     * The plan does not check that the live schema actually reached `targetSchemaChecksum`; the executor
     * proves that by introspection before it calls this, and this only guards the status it transitions from.
     *
     * @param   array<string, mixed>  $outcome  Execution report to store, as the executor summarised the run.
     * @param   DateTimeImmutable     $at       Instant to record as the update time.
     *
     * @return  self  A completed copy at the next revision, keeping the fence the run held.
     *
     * @throws  InvalidBusinessSchema  When the plan is not currently executing.
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the outcome holds a value
     *          that cannot be canonically encoded, such as a float or an object.
     *
     * @since   2.0.0
     */
    public function complete(array $outcome, DateTimeImmutable $at): self
    {
        $this->assertExecuting();

        return $this->withState(
            SchemaPlanStatus::Completed,
            $this->revision + 1,
            $this->approval,
            $this->recoveryEvidenceId,
            $this->executionFence,
            $outcome,
            $at,
        );
    }

    /**
     * Record that execution stopped on a known error while the physical state is still understood.
     *
     * This and `recoveryRequired()` both stop an executing plan on a code and both leave it resumable; the
     * difference is the signal. Failed says the journal still explains where the database stands, so a
     * retry needs no inspection first. The code is merged into the stored outcome under `error_code`,
     * overriding any entry of that name the caller supplied.
     *
     * @param   string                $errorCode  Lowercase dotted code naming the failure, at most 64 bytes.
     * @param   array<string, mixed>  $outcome    Execution report to store alongside the code.
     * @param   DateTimeImmutable     $at         Instant to record as the update time.
     *
     * @return  self  A failed copy at the next revision, keeping the fence the run held.
     *
     * @throws  InvalidBusinessSchema  When the plan is not currently executing, or the error code is outside
     *          its grammar.
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the outcome holds a value
     *          that cannot be canonically encoded, such as a float or an object.
     *
     * @since   2.0.0
     */
    public function fail(string $errorCode, array $outcome, DateTimeImmutable $at): self
    {
        $this->assertExecuting();
        self::assertErrorCode($errorCode);

        return $this->withState(
            SchemaPlanStatus::Failed,
            $this->revision + 1,
            $this->approval,
            $this->recoveryEvidenceId,
            $this->executionFence,
            [...$outcome, 'error_code' => $errorCode],
            $at,
        );
    }

    /**
     * Park the plan where the journal may no longer describe the live schema, pending operator judgement.
     *
     * This is what `BusinessSchemaExecutor` records whenever a run is interrupted, because a step that threw
     * mid-statement may have left the database on either side of it. Mechanically it is `fail()` under a
     * different status, including the code merged into the outcome under `error_code`.
     *
     * @param   string                $errorCode  Lowercase dotted code naming the failure, at most 64 bytes.
     * @param   array<string, mixed>  $outcome    Everything known about where execution stopped.
     * @param   DateTimeImmutable     $at         Instant to record as the update time.
     *
     * @return  self  A recovery-required copy at the next revision, keeping the fence the run held.
     *
     * @throws  InvalidBusinessSchema  When the plan is not currently executing, or the error code is outside
     *          its grammar.
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the outcome holds a value
     *          that cannot be canonically encoded, such as a float or an object.
     *
     * @since   2.0.0
     */
    public function recoveryRequired(string $errorCode, array $outcome, DateTimeImmutable $at): self
    {
        $this->assertExecuting();
        self::assertErrorCode($errorCode);

        return $this->withState(
            SchemaPlanStatus::RecoveryRequired,
            $this->revision + 1,
            $this->approval,
            $this->recoveryEvidenceId,
            $this->executionFence,
            [...$outcome, 'error_code' => $errorCode],
            $at,
        );
    }

    /**
     * Close out an interrupted plan once its partial effects have been resolved.
     *
     * This is the terminal status an interrupted plan reaches once its partial effects have been undone or
     * reconciled. It settles the plan without claiming the migration succeeded, which is what separates a
     * resolved failure from one still waiting on someone.
     *
     * @param   array<string, mixed>  $outcome  Report of what the compensation actually did.
     * @param   DateTimeImmutable     $at       Instant to record as the update time.
     *
     * @return  self  A compensated copy at the next revision, keeping the fence the interrupted run held.
     *
     * @throws  InvalidBusinessSchema  When the plan is neither failed nor awaiting recovery.
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the outcome holds a value
     *          that cannot be canonically encoded, such as a float or an object.
     *
     * @since   2.0.0
     */
    public function compensate(array $outcome, DateTimeImmutable $at): self
    {
        if (!in_array($this->status, [SchemaPlanStatus::Failed, SchemaPlanStatus::RecoveryRequired], true)) {
            throw new InvalidBusinessSchema('Only a failed schema plan can be recorded as compensated.');
        }

        return $this->withState(
            SchemaPlanStatus::Compensated,
            $this->revision + 1,
            $this->approval,
            $this->recoveryEvidenceId,
            $this->executionFence,
            $outcome,
            $at,
        );
    }

    /**
     * Export the content that defines what this plan would do, without any of its lifecycle state.
     *
     * Identity, status, revision, and every piece of execution evidence are left out on purpose: this is
     * the array `checksum()` fingerprints, so moving the plan through its lifecycle never disturbs the
     * value an approval was bound to, while any change to the operations or the bindings does.
     *
     * @return  array<string, mixed>  The definition and schema bindings, the operations as documents in
     *          ordinal order, and the plan's risk.
     *
     * @since   2.0.0
     */
    public function canonicalPlan(): array
    {
        return [
            'definition_id' => $this->definitionId,
            'site_identifier' => $this->siteIdentifier,
            'from_definition_version' => $this->fromDefinitionVersion,
            'to_definition_version' => $this->toDefinitionVersion,
            'from_definition_checksum' => $this->fromDefinitionChecksum,
            'to_definition_checksum' => $this->toDefinitionChecksum,
            'from_schema_checksum' => $this->fromSchemaChecksum,
            'target_schema_checksum' => $this->targetSchemaChecksum,
            'operations' => array_map(
                static fn (SchemaOperation $operation): array => $operation->toArray(),
                $this->operations,
            ),
            'risk' => $this->risk->value,
        ];
    }

    /**
     * Compute the content address an approval binds to and a reloaded plan is re-verified against.
     *
     * @return  string  Lowercase SHA-256 over the canonical JSON encoding of `canonicalPlan()`.
     *
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the plan holds more than
     *          512 operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        return CanonicalDefinitionJson::checksum($this->canonicalPlan());
    }

    /**
     * Export the whole plan in the shape persisted in the plan table and served to the API.
     *
     * The `plan_checksum` entry is recomputed on every export rather than carried as state, which is what
     * lets `fromArray()` catch a stored document that was edited underneath the application.
     *
     * @return  array<string, mixed>  The canonical plan plus identity, status, revision, creator, timestamps,
     *          execution evidence, and the recomputed `plan_checksum`.
     *
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the plan holds more than
     *          512 operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            ...$this->canonicalPlan(),
            'status' => $this->status->value,
            'revision' => $this->revision,
            'created_by' => $this->createdBy,
            'created_at' => SchemaDocument::formatDate($this->createdAt),
            'approval' => $this->approval?->toArray(),
            'recovery_evidence_id' => $this->recoveryEvidenceId,
            'execution_fence' => $this->executionFence,
            'outcome' => $this->outcome,
            'updated_at' => SchemaDocument::formatDate($this->updatedAt),
            'plan_checksum' => $this->checksum(),
        ];
    }

    /**
     * Fingerprint a candidate operation list against this plan's bindings.
     *
     * The constructor needs the checksum before it may assign `$this->operations`, so `checksum()` is not
     * available to it yet. The array assembled here must stay identical in shape and key order to the one
     * `canonicalPlan()` builds, or an approval granted through one would never match the other.
     *
     * @param   list<SchemaOperation>  $operations  Steps to fingerprint, already sorted into ordinal order.
     *
     * @return  string  Lowercase SHA-256 over the canonical encoding of those steps and this plan's bindings.
     *
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When more than 512 operations
     *          are supplied, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    private function checksumFor(array $operations): string
    {
        return CanonicalDefinitionJson::checksum([
            'definition_id' => $this->definitionId,
            'site_identifier' => $this->siteIdentifier,
            'from_definition_version' => $this->fromDefinitionVersion,
            'to_definition_version' => $this->toDefinitionVersion,
            'from_definition_checksum' => $this->fromDefinitionChecksum,
            'to_definition_checksum' => $this->toDefinitionChecksum,
            'from_schema_checksum' => $this->fromSchemaChecksum,
            'target_schema_checksum' => $this->targetSchemaChecksum,
            'operations' => array_map(
                static fn (SchemaOperation $operation): array => $operation->toArray(),
                $operations,
            ),
            'risk' => $this->risk->value,
        ]);
    }

    /**
     * Copy the plan with new lifecycle state, leaving what it would do untouched.
     *
     * Every public transition ends here, so each one is re-validated by the constructor rather than trusting
     * its own guard, and none of them can quietly alter the operations an approval was granted against.
     *
     * @param   SchemaPlanStatus           $status              Status the copy carries.
     * @param   int                        $revision            Persistence revision for the copy.
     * @param   SchemaPlanApproval|null    $approval            Approval evidence to carry forward, if any.
     * @param   string|null                $recoveryEvidenceId  Restore-drill UUID to carry forward, if any.
     * @param   int|null                   $executionFence      Fence the copy holds, or null before execution.
     * @param   array<string, mixed>|null  $outcome             Execution result for the copy, or null to clear it.
     * @param   DateTimeImmutable          $updatedAt           Instant to record as the update time.
     *
     * @return  self  The copied plan.
     *
     * @throws  InvalidBusinessSchema  When the requested combination breaks a plan invariant, such as an
     *          evidence set the status does not permit or an update time before creation.
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the outcome cannot be
     *          canonically encoded, or an approved plan holds more than 512 operations.
     *
     * @since   2.0.0
     */
    private function withState(
        SchemaPlanStatus $status,
        int $revision,
        ?SchemaPlanApproval $approval,
        ?string $recoveryEvidenceId,
        ?int $executionFence,
        ?array $outcome,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $this->id,
            $this->definitionId,
            $this->siteIdentifier,
            $this->fromDefinitionVersion,
            $this->toDefinitionVersion,
            $this->fromDefinitionChecksum,
            $this->toDefinitionChecksum,
            $this->fromSchemaChecksum,
            $this->targetSchemaChecksum,
            $this->operations,
            $this->risk,
            $status,
            $revision,
            $this->createdBy,
            $this->createdAt,
            $approval,
            $recoveryEvidenceId,
            $executionFence,
            $outcome,
            $updatedAt,
        );
    }

    /**
     * Reject an evidence combination that the plan's lifecycle could never legitimately produce.
     *
     * Each status admits exactly one shape: pending carries no evidence at all, cancelled never holds a
     * fence, approved holds evidence but no execution state, executing holds a fence and no outcome, and
     * every terminal executed status holds both. Anything past pending or cancelled needs its approval, and
     * a locking or destructive plan additionally needs its recovery evidence. Running this from the
     * constructor is what makes the rule apply to plans loaded from storage as well as to transitions.
     *
     * @param   SchemaPlanStatus           $status              Status being asserted.
     * @param   SchemaPlanApproval|null    $approval            Approval evidence offered with it.
     * @param   string|null                $recoveryEvidenceId  Restore-drill UUID offered with it.
     * @param   int|null                   $executionFence      Execution fence offered with it.
     * @param   array<string, mixed>|null  $outcome             Execution result offered with it.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the evidence does not match what the status requires or forbids.
     *
     * @since   2.0.0
     */
    private function assertState(
        SchemaPlanStatus $status,
        ?SchemaPlanApproval $approval,
        ?string $recoveryEvidenceId,
        ?int $executionFence,
        ?array $outcome,
    ): void {
        if ($status === SchemaPlanStatus::PendingApproval) {
            if ($approval !== null || $recoveryEvidenceId !== null || $executionFence !== null || $outcome !== null) {
                throw new InvalidBusinessSchema('A pending schema plan cannot contain execution state.');
            }
            return;
        }
        if ($status === SchemaPlanStatus::Cancelled) {
            if ($executionFence !== null) {
                throw new InvalidBusinessSchema('A cancelled schema plan cannot contain an execution fence.');
            }
            return;
        }
        if ($approval === null) {
            throw new InvalidBusinessSchema('An active or completed schema plan requires its approval evidence.');
        }
        if ($this->risk->requiresRecoveryEvidence() && $recoveryEvidenceId === null) {
            throw new InvalidBusinessSchema('A locking or destructive schema plan requires recovery evidence.');
        }
        if ($status === SchemaPlanStatus::Approved && ($executionFence !== null || $outcome !== null)) {
            throw new InvalidBusinessSchema('An approved schema plan cannot contain execution outcome state.');
        }
        if ($status === SchemaPlanStatus::Executing && ($executionFence === null || $outcome !== null)) {
            throw new InvalidBusinessSchema('An executing schema plan requires only a positive execution fence.');
        }
        if (
            in_array(
                $status,
                [
                    SchemaPlanStatus::Completed,
                    SchemaPlanStatus::Failed,
                    SchemaPlanStatus::RecoveryRequired,
                    SchemaPlanStatus::Compensated,
                ],
                true,
            )
            && ($executionFence === null || $outcome === null)
        ) {
            throw new InvalidBusinessSchema('A terminal executed schema plan requires its fence and outcome.');
        }
    }

    /**
     * Require the plan to be mid-execution before an execution outcome may be recorded against it.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the plan is in any status other than executing.
     *
     * @since   2.0.0
     */
    private function assertExecuting(): void
    {
        if ($this->status !== SchemaPlanStatus::Executing) {
            throw new InvalidBusinessSchema('A schema plan must be executing to record an execution outcome.');
        }
    }

    /**
     * Require a failure code narrow enough to be stored, indexed, and matched on by an operator.
     *
     * The grammar is a lowercase leading letter followed by up to 63 more of letter, digit, dot, underscore,
     * or hyphen, which keeps a driver message or a raw exception string out of the persisted outcome.
     *
     * @param   string  $errorCode  Candidate code.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the code breaks that grammar or exceeds 64 bytes.
     *
     * @since   2.0.0
     */
    private static function assertErrorCode(string $errorCode): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $errorCode) !== 1) {
            throw new InvalidBusinessSchema('A schema execution error code is invalid.');
        }
    }
}
