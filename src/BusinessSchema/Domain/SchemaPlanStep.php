<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Domain;

use DateTimeImmutable;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;

/**
 * Durable journal entry for one operation of a schema plan, rewritten before and after every attempt.
 *
 * The executor never asks the live database how far a plan got; it reads these rows. Each one records
 * which attempt is current, the execution fence that attempt holds, the schema checksum the step began
 * from, the keyset a long rewrite reached, and the outcome or error code it ended with. A resumed
 * execution can therefore skip finished steps, continue a half-written rewrite from its cursor, and —
 * because every row names the fence that wrote it — let the repository refuse a write from a
 * superseded run. Every transition returns a new instance, and each instance re-checks that its state
 * and the evidence it carries actually agree.
 *
 * @since  2.0.0
 */
final readonly class SchemaPlanStep
{
    /**
     * Keyset position a chunked rewrite has durably reached, or null when it never checkpointed.
     *
     * @var    array<string, bool|int|string>|null
     * @since  2.0.0
     */
    public ?array $cursor;

    /**
     * Facts the finished attempt recorded, or null until an attempt completes or fails.
     *
     * @var    array<string, mixed>|null
     * @since  2.0.0
     */
    public ?array $outcome;

    /**
     * Capture one journal entry and prove its state and its evidence agree.
     *
     * @param   string                               $planId                Plan this step belongs to, as a UUID.
     * @param   int                                  $ordinal               Position in the plan, from one, gapless.
     * @param   string                               $operationChecksum     Content address of the operation.
     * @param   SchemaOperationKind                  $operationKind         Semantic change this step realises.
     * @param   SchemaRisk                           $risk                  Impact class the operation carries.
     * @param   SchemaStepStatus                     $state                 Journal state this instance represents.
     * @param   int                                  $attempt               Attempts started; zero while pending.
     * @param   ?int                                 $executionFence        Fence of the current attempt.
     * @param   array<string, bool|int|string>|null  $cursor                Keyset a chunked rewrite reached.
     * @param   ?string                              $beforeSchemaChecksum  Schema checksum the attempt began from.
     * @param   ?string                              $afterSchemaChecksum   Schema checksum the step produced.
     * @param   array<string, mixed>|null            $outcome               Facts the finished attempt recorded.
     * @param   ?string                              $errorCode             Bounded code naming why it failed.
     * @param   ?DateTimeImmutable                   $startedAt             When the current attempt started.
     * @param   ?DateTimeImmutable                   $completedAt           When the step became terminal.
     * @param   ?DateTimeImmutable                   $updatedAt             When this row was last written.
     *
     * @throws  InvalidBusinessSchema  When the plan ID is not a UUID, the ordinal or attempt is outside
     *          its bounds, a checksum is not a lowercase SHA-256 digest, the fence is not positive,
     *          the error code breaks its grammar, the cursor or outcome is not a string-keyed object,
     *          the cursor holds a value that is not a bool, int, or string, the state and the recorded
     *          evidence disagree, or a completion or update time precedes the start.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the cursor or the
     *          outcome holds a value that cannot be canonically encoded, such as a float or an object.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $planId,
        public int $ordinal,
        public string $operationChecksum,
        public SchemaOperationKind $operationKind,
        public SchemaRisk $risk,
        public SchemaStepStatus $state,
        public int $attempt = 0,
        public ?int $executionFence = null,
        ?array $cursor = null,
        public ?string $beforeSchemaChecksum = null,
        public ?string $afterSchemaChecksum = null,
        ?array $outcome = null,
        public ?string $errorCode = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $completedAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
        SchemaDocument::assertUuid($planId, 'The schema-plan step plan ID');
        if ($ordinal < 1 || $ordinal > 100_000 || $attempt < 0 || $attempt > 1_000) {
            throw new InvalidBusinessSchema('A schema-plan step ordinal or attempt is outside the supported bounds.');
        }
        SchemaDocument::assertChecksum($operationChecksum, 'The schema operation checksum');
        if ($executionFence !== null && $executionFence < 1) {
            throw new InvalidBusinessSchema('A schema-plan step execution fence must be positive.');
        }
        SchemaDocument::assertChecksum($beforeSchemaChecksum, 'The prior step schema checksum', true);
        SchemaDocument::assertChecksum($afterSchemaChecksum, 'The resulting step schema checksum', true);
        if ($errorCode !== null && preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $errorCode) !== 1) {
            throw new InvalidBusinessSchema('A schema-plan step error code is invalid.');
        }
        foreach ([$cursor, $outcome] as $document) {
            if ($document !== null) {
                SchemaDocument::assertObjectValue($document, 'A schema-plan cursor or outcome');
                CanonicalDefinitionJson::encode($document);
            }
        }
        foreach ($cursor ?? [] as $value) {
            if (!is_bool($value) && !is_int($value) && !is_string($value)) {
                throw new InvalidBusinessSchema('A schema-plan cursor contains an unsupported value.');
            }
        }
        $this->cursor = $cursor;
        $this->outcome = $outcome;
        $this->assertState();
        if ($completedAt !== null && $startedAt !== null && $completedAt < $startedAt) {
            throw new InvalidBusinessSchema('A schema-plan step cannot complete before it starts.');
        }
        if ($updatedAt !== null && $startedAt !== null && $updatedAt < $startedAt) {
            throw new InvalidBusinessSchema('A schema-plan step cannot be updated before it starts.');
        }
    }

    /**
     * Rebuild a step from the journal row the plan repository read.
     *
     * @param   array<string, mixed>  $document  Stored step object, as written by `toArray()`.
     *
     * @return  self  The revalidated journal entry.
     *
     * @throws  InvalidBusinessSchema  When the document carries an unknown property, a field is absent
     *          or misshapen, the stored kind, risk, or state is not one this build knows, or a step
     *          invariant fails.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When a stored cursor or
     *          outcome cannot be canonically encoded.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly(
            $document,
            [
                'plan_id', 'ordinal', 'operation_checksum', 'operation_kind', 'risk', 'state', 'attempt', 'cursor',
                'execution_fence', 'before_schema_checksum', 'after_schema_checksum', 'outcome', 'error_code',
                'started_at',
                'completed_at', 'updated_at',
            ],
            'A schema-plan step',
        );
        $kind = SchemaOperationKind::tryFrom(SchemaDocument::string($document, 'operation_kind'))
            ?? throw new InvalidBusinessSchema('A schema-plan step operation kind is invalid.');
        $risk = SchemaRisk::tryFrom(SchemaDocument::string($document, 'risk'))
            ?? throw new InvalidBusinessSchema('A schema-plan step risk is invalid.');
        $state = SchemaStepStatus::tryFrom(SchemaDocument::string($document, 'state'))
            ?? throw new InvalidBusinessSchema('A schema-plan step state is invalid.');

        return new self(
            SchemaDocument::string($document, 'plan_id'),
            SchemaDocument::integer($document, 'ordinal'),
            SchemaDocument::string($document, 'operation_checksum'),
            $kind,
            $risk,
            $state,
            SchemaDocument::integer($document, 'attempt'),
            SchemaDocument::nullableInteger($document, 'execution_fence'),
            self::cursor($document),
            SchemaDocument::nullableString($document, 'before_schema_checksum'),
            SchemaDocument::nullableString($document, 'after_schema_checksum'),
            SchemaDocument::object($document, 'outcome', true),
            SchemaDocument::nullableString($document, 'error_code'),
            self::optionalDate($document, 'started_at'),
            self::optionalDate($document, 'completed_at'),
            self::optionalDate($document, 'updated_at'),
        );
    }

    /**
     * Create the untouched journal entry the planner saves beside a newly planned operation.
     *
     * @param   string              $planId     Plan the step belongs to, as a UUID.
     * @param   SchemaOperation     $operation  Operation this step will execute; supplies the ordinal,
     *          kind, risk, and content address the journal records.
     * @param   ?DateTimeImmutable  $updatedAt  When the row was written, which the repository requires
     *          before it will persist the step.
     *
     * @return  self  A pending step carrying no execution state at all.
     *
     * @throws  InvalidBusinessSchema  When the plan ID is not a canonical UUID.
     *
     * @since   2.0.0
     */
    public static function pending(
        string $planId,
        SchemaOperation $operation,
        ?DateTimeImmutable $updatedAt = null,
    ): self {
        return new self(
            $planId,
            $operation->ordinal,
            $operation->checksum(),
            $operation->kind,
            $operation->risk,
            SchemaStepStatus::Pending,
            updatedAt: $updatedAt,
        );
    }

    /**
     * Begin a fresh attempt on this step under the executor's current fence.
     *
     * The prior schema checksum is supplied rather than remembered, because a first attempt is measured
     * against the chain value the preceding steps produced. A failed step re-enters here, which clears
     * the recorded error and outcome so the retry is journaled from a clean slate.
     *
     * @param   int                $executionFence        Fence the executing run holds.
     * @param   string             $beforeSchemaChecksum  Schema checksum in effect before this step runs.
     * @param   DateTimeImmutable  $at                    Instant the attempt started.
     *
     * @return  self  A running step with the attempt counter advanced and the terminal state cleared.
     *
     * @throws  InvalidBusinessSchema  When the step is neither pending nor failed, the fence is not
     *          positive, or the prior checksum is not a lowercase SHA-256 digest.
     *
     * @since   2.0.0
     */
    public function start(int $executionFence, string $beforeSchemaChecksum, DateTimeImmutable $at): self
    {
        if (!in_array($this->state, [SchemaStepStatus::Pending, SchemaStepStatus::Failed], true)) {
            throw new InvalidBusinessSchema('Only a pending or failed schema-plan step can start an attempt.');
        }

        return new self(
            $this->planId,
            $this->ordinal,
            $this->operationChecksum,
            $this->operationKind,
            $this->risk,
            SchemaStepStatus::Running,
            $this->attempt + 1,
            $executionFence,
            $this->cursor,
            $beforeSchemaChecksum,
            null,
            null,
            null,
            $at,
            null,
            $at,
        );
    }

    /**
     * Take over a step whose previous attempt was interrupted, under a new fence.
     *
     * Unlike `start()` the prior schema checksum is kept rather than supplied, so the recovering run
     * measures itself against the same starting point the abandoned attempt did, and any cursor already
     * reached is carried across so a long rewrite is not begun again from the top.
     *
     * @param   int                $executionFence  Fence the recovering run holds.
     * @param   DateTimeImmutable  $at              Instant the new attempt started.
     *
     * @return  self  A running step with the attempt counter advanced.
     *
     * @throws  InvalidBusinessSchema  When the step is neither running nor failed, it never recorded a
     *          prior schema checksum, or the fence is not positive.
     *
     * @since   2.0.0
     */
    public function resume(int $executionFence, DateTimeImmutable $at): self
    {
        if (!in_array($this->state, [SchemaStepStatus::Running, SchemaStepStatus::Failed], true)) {
            throw new InvalidBusinessSchema('Only an interrupted schema-plan step can resume.');
        }
        if ($this->beforeSchemaChecksum === null) {
            throw new InvalidBusinessSchema('An interrupted schema-plan step has no prior checksum.');
        }

        return new self(
            $this->planId,
            $this->ordinal,
            $this->operationChecksum,
            $this->operationKind,
            $this->risk,
            SchemaStepStatus::Running,
            $this->attempt + 1,
            $executionFence,
            $this->cursor,
            $this->beforeSchemaChecksum,
            null,
            null,
            null,
            $at,
            null,
            $at,
        );
    }

    /**
     * Record how far a chunked rewrite has durably progressed, without ending the attempt.
     *
     * The executor writes a checkpoint after each committed chunk, so an interruption resumes from the
     * last keyset position instead of rewriting rows that were already converted. The attempt counter
     * and start time are untouched: this is progress within one attempt, not a new one.
     *
     * @param   array<string, bool|int|string>  $cursor  Keyset the last committed chunk reached.
     * @param   DateTimeImmutable               $at      Instant the checkpoint was written.
     *
     * @return  self  The same running attempt with its cursor and update time advanced.
     *
     * @throws  InvalidBusinessSchema  When the step is not running, or the cursor is not a string-keyed
     *          object of bool, int, and string values.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the cursor cannot be
     *          canonically encoded.
     *
     * @since   2.0.0
     */
    public function checkpoint(array $cursor, DateTimeImmutable $at): self
    {
        if ($this->state !== SchemaStepStatus::Running) {
            throw new InvalidBusinessSchema('Only a running schema-plan step can record a cursor checkpoint.');
        }

        return new self(
            $this->planId,
            $this->ordinal,
            $this->operationChecksum,
            $this->operationKind,
            $this->risk,
            $this->state,
            $this->attempt,
            $this->executionFence,
            $cursor,
            $this->beforeSchemaChecksum,
            null,
            null,
            null,
            $this->startedAt,
            null,
            $at,
        );
    }

    /**
     * Read the stored cursor and narrow it to the value types a cursor is allowed to hold.
     *
     * The nested reader only guarantees a string-keyed object, so the value check here is what lets the
     * rebuilt step be typed as a keyset rather than an arbitrary document.
     *
     * @param   array<string, mixed>  $document  Stored step object being rebuilt.
     *
     * @return  array<string, bool|int|string>|null  The keyset position, or null when none was stored.
     *
     * @throws  InvalidBusinessSchema  When the stored cursor is not an object, or holds a value that is
     *          not a bool, int, or string.
     *
     * @since   2.0.0
     */
    private static function cursor(array $document): ?array
    {
        $cursor = SchemaDocument::object($document, 'cursor', true);
        foreach ($cursor ?? [] as $value) {
            if (!is_bool($value) && !is_int($value) && !is_string($value)) {
                throw new InvalidBusinessSchema('A stored schema-plan cursor contains an unsupported value.');
            }
        }

        /** @var array<string, bool|int|string>|null $cursor */
        return $cursor;
    }

    /**
     * Close the attempt as successful and record the schema checksum it produced.
     *
     * The resulting checksum is the value the next step will start from, so writing it here is what
     * extends the chain a resumed execution verifies itself against.
     *
     * @param   string                $afterSchemaChecksum  Schema checksum in effect after this step.
     * @param   array<string, mixed>  $outcome              Facts worth journaling about the attempt.
     * @param   DateTimeImmutable     $at                   Instant the step completed.
     *
     * @return  self  A completed step carrying its resulting checksum and outcome.
     *
     * @throws  InvalidBusinessSchema  When the step is not running, the resulting checksum is not a
     *          lowercase SHA-256 digest, or the outcome is not a string-keyed object.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the outcome cannot
     *          be canonically encoded.
     *
     * @since   2.0.0
     */
    public function complete(string $afterSchemaChecksum, array $outcome, DateTimeImmutable $at): self
    {
        if ($this->state !== SchemaStepStatus::Running) {
            throw new InvalidBusinessSchema('Only a running schema-plan step can complete.');
        }

        return new self(
            $this->planId,
            $this->ordinal,
            $this->operationChecksum,
            $this->operationKind,
            $this->risk,
            SchemaStepStatus::Completed,
            $this->attempt,
            $this->executionFence,
            $this->cursor,
            $this->beforeSchemaChecksum,
            $afterSchemaChecksum,
            $outcome,
            null,
            $this->startedAt,
            $at,
            $at,
        );
    }

    /**
     * Close the attempt as failed, leaving the step open to a further attempt.
     *
     * A failed step is not settled: `start()` and `resume()` both accept one, which is how operator
     * recovery picks execution back up. The cursor survives, so a rewrite that failed part way is not
     * repeated from the top, while the resulting checksum stays unset: the step reached no verified end
     * state, so there is nothing for a later step to chain onto.
     *
     * @param   string                $errorCode  Lowercase failure code of at most 64 characters.
     * @param   array<string, mixed>  $outcome    Facts worth journaling about the failed attempt.
     * @param   DateTimeImmutable     $at         Instant the attempt was abandoned.
     *
     * @return  self  A failed step carrying its error code and outcome.
     *
     * @throws  InvalidBusinessSchema  When the step is not running, the error code breaks its grammar,
     *          or the outcome is not a string-keyed object.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the outcome cannot
     *          be canonically encoded.
     *
     * @since   2.0.0
     */
    public function fail(string $errorCode, array $outcome, DateTimeImmutable $at): self
    {
        if ($this->state !== SchemaStepStatus::Running) {
            throw new InvalidBusinessSchema('Only a running schema-plan step can fail.');
        }

        return new self(
            $this->planId,
            $this->ordinal,
            $this->operationChecksum,
            $this->operationKind,
            $this->risk,
            SchemaStepStatus::Failed,
            $this->attempt,
            $this->executionFence,
            $this->cursor,
            $this->beforeSchemaChecksum,
            null,
            $outcome,
            $errorCode,
            $this->startedAt,
            $at,
            $at,
        );
    }

    /**
     * Export the step in the document shape the plan repository persists.
     *
     * @return  array<string, mixed>  Every journal field, with the enums reduced to their backing
     *          values and the three timestamps rendered as UTC text or left null.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'plan_id' => $this->planId,
            'ordinal' => $this->ordinal,
            'operation_checksum' => $this->operationChecksum,
            'operation_kind' => $this->operationKind->value,
            'risk' => $this->risk->value,
            'state' => $this->state->value,
            'attempt' => $this->attempt,
            'execution_fence' => $this->executionFence,
            'cursor' => $this->cursor,
            'before_schema_checksum' => $this->beforeSchemaChecksum,
            'after_schema_checksum' => $this->afterSchemaChecksum,
            'outcome' => $this->outcome,
            'error_code' => $this->errorCode,
            'started_at' => $this->startedAt === null ? null : SchemaDocument::formatDate($this->startedAt),
            'completed_at' => $this->completedAt === null ? null : SchemaDocument::formatDate($this->completedAt),
            'updated_at' => $this->updatedAt === null ? null : SchemaDocument::formatDate($this->updatedAt),
        ];
    }

    /**
     * Refuse any instance whose state and recorded evidence contradict each other.
     *
     * A pending step must carry no execution state whatsoever; any attempted step must name its
     * attempt, fence, start time, and prior checksum; a running one must carry none of the terminal
     * evidence; and a terminal one must carry a completion time, plus a resulting checksum and outcome
     * when completed, or an error code and outcome when failed.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the state and the recorded evidence disagree.
     *
     * @since   2.0.0
     */
    private function assertState(): void
    {
        if ($this->state === SchemaStepStatus::Pending) {
            if (
                $this->attempt !== 0 || $this->executionFence !== null || $this->cursor !== null
                || $this->beforeSchemaChecksum !== null
                || $this->afterSchemaChecksum !== null || $this->outcome !== null || $this->errorCode !== null
                || $this->startedAt !== null || $this->completedAt !== null
            ) {
                throw new InvalidBusinessSchema('A pending schema-plan step cannot contain execution state.');
            }
            return;
        }
        if (
            $this->attempt < 1 || $this->executionFence === null
            || $this->startedAt === null || $this->beforeSchemaChecksum === null
        ) {
            throw new InvalidBusinessSchema('An attempted schema-plan step requires its start evidence.');
        }
        if ($this->state === SchemaStepStatus::Running) {
            if (
                $this->completedAt !== null || $this->afterSchemaChecksum !== null
                || $this->outcome !== null || $this->errorCode !== null
            ) {
                throw new InvalidBusinessSchema('A running schema-plan step contains terminal outcome state.');
            }
            return;
        }
        if ($this->completedAt === null) {
            throw new InvalidBusinessSchema('A terminal schema-plan step requires a completion time.');
        }
        if ($this->state === SchemaStepStatus::Completed) {
            if ($this->afterSchemaChecksum === null || $this->outcome === null || $this->errorCode !== null) {
                throw new InvalidBusinessSchema(
                    'A completed schema-plan step requires a resulting checksum and outcome.',
                );
            }
            return;
        }
        if ($this->state === SchemaStepStatus::Failed && ($this->outcome === null || $this->errorCode === null)) {
            throw new InvalidBusinessSchema('A failed schema-plan step requires an error code and outcome.');
        }
    }

    /**
     * Read one of the step's optional timestamps out of a stored document.
     *
     * @param   array<string, mixed>  $document  Stored step object being rebuilt.
     * @param   string                $key       Property name holding the timestamp.
     *
     * @return  ?DateTimeImmutable  The instant in UTC, or null when the property is absent or null.
     *
     * @throws  InvalidBusinessSchema  When the property is present but is not a readable timestamp.
     *
     * @since   2.0.0
     */
    private static function optionalDate(array $document, string $key): ?DateTimeImmutable
    {
        $value = SchemaDocument::nullableString($document, $key);

        return $value === null ? null : SchemaDocument::date($value, 'The schema-plan step ' . $key);
    }
}
