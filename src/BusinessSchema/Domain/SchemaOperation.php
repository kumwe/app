<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Domain;

use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;

/**
 * One approvable, journaled step of a schema plan, described semantically rather than as SQL.
 *
 * An operation says what should change, what the affected object looked like before, and what it must look
 * like afterwards; the physical gateway turns that into statements for the driver in use and uses the same
 * two states to decide whether the step is already satisfied. It also carries the two facts recovery needs
 * before anything runs: how risky the step is, and what an operator must do if execution stops on it.
 * Operations are content addressed, so a persisted step cannot be edited without invalidating its plan.
 *
 * @since  2.0.0
 */
final readonly class SchemaOperation
{
    /**
     * What an interrupted execution demands of the operator, from cheapest to most severe.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const RECOVERY_IMPLICATIONS = [
        'none',
        'compensate_safe_addition',
        'resume_required',
        'restore_required',
        'manual_reconciliation',
    ];

    /**
     * State of the affected object before the step, or null when the step only adds.
     *
     * @var    array<string, mixed>|null
     * @since  2.0.0
     */
    public ?array $before;

    /**
     * State the affected object must reach, or null when the step only removes.
     *
     * @var    array<string, mixed>|null
     * @since  2.0.0
     */
    public ?array $after;

    /**
     * Describe one step and prove it is coherent before it can be planned.
     *
     * @param   int                        $ordinal              Position in the plan, from one, gapless.
     * @param   SchemaOperationKind        $kind                 Semantic change the gateway must realise.
     * @param   SchemaRisk                 $risk                 Impact class this step contributes to the plan.
     * @param   string                     $table                Logical table the step acts on.
     * @param   string                     $subject              Logical object within that table, or a path.
     * @param array<string, mixed>|null $before Prior state of the subject, for verification and recovery.
     * @param array<string, mixed>|null $after Target state of the subject, for execution and verification.
     * @param   bool                       $requiresBackfill     Whether the step rewrites rows rather than shape.
     * @param   string                     $recoveryImplication  One of the declared recovery implications.
     *
     * @throws  InvalidBusinessSchema  When the ordinal is outside one to 100000, the table is not a
     *          metadata identifier, the subject is over 512 bytes or is neither a
     *          metadata identifier nor a slash path, the recovery implication is
     *          not a declared one, a row-rewriting step claims to be online-safe
     *          additive, or either state is not a string-keyed object.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When either state holds a
     *          value that cannot be canonically encoded, such as a float or an object.
     *
     * @since   2.0.0
     */
    public function __construct(
        public int $ordinal,
        public SchemaOperationKind $kind,
        public SchemaRisk $risk,
        public string $table,
        public string $subject,
        ?array $before = null,
        ?array $after = null,
        public bool $requiresBackfill = false,
        public string $recoveryImplication = 'none',
    ) {
        if ($ordinal < 1 || $ordinal > 100_000) {
            throw new InvalidBusinessSchema('A schema operation ordinal is outside the supported bounds.');
        }
        SchemaDocument::assertIdentifier($table, 'The schema operation table');
        if (
            strlen($subject) > 512
            || preg_match('#^(?:/[a-z0-9._:/-]+|[a-z][a-z0-9]*(?:[._:-][a-z0-9]+)*)$#D', $subject) !== 1
        ) {
            throw new InvalidBusinessSchema('A schema operation subject must be a validated metadata path.');
        }
        if (!in_array($recoveryImplication, self::RECOVERY_IMPLICATIONS, true)) {
            throw new InvalidBusinessSchema('A schema operation recovery implication is invalid.');
        }
        if ($requiresBackfill && $risk === SchemaRisk::OnlineSafeAdditive) {
            throw new InvalidBusinessSchema('A backfill operation cannot be classified as online-safe additive.');
        }
        foreach ([$before, $after] as $state) {
            if ($state !== null) {
                SchemaDocument::assertObjectValue($state, 'Schema operation before/after state');
                CanonicalDefinitionJson::encode($state);
            }
        }
        $this->before = $before;
        $this->after = $after;
    }

    /**
     * Rebuild an operation from its persisted document and confirm it was not tampered with.
     *
     * When the stored document carries a `checksum`, it is compared against the checksum recomputed from
     * the decoded content, so an edited journal row is refused rather than replayed.
     *
     * @param   array<string, mixed>  $document  Stored operation object, as written by `persistedArray()`.
     *
     * @return  self  The revalidated operation.
     *
     * @throws  InvalidBusinessSchema  When the document carries an unknown property, a field is missing or
     *          misshapen, the stored kind or risk is not a known one, an operation
     *          invariant fails, or the stored checksum does not match the content.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When a stored state holds a
     *          value that cannot be canonically encoded.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly(
            $document,
            [
                'ordinal', 'kind', 'risk', 'table', 'subject', 'before', 'after', 'requires_backfill',
                'recovery_implication', 'checksum',
            ],
            'A schema operation',
        );
        $kind = SchemaOperationKind::tryFrom(SchemaDocument::string($document, 'kind'))
            ?? throw new InvalidBusinessSchema('A schema operation kind is invalid.');
        $risk = SchemaRisk::tryFrom(SchemaDocument::string($document, 'risk'))
            ?? throw new InvalidBusinessSchema('A schema operation risk is invalid.');
        $operation = new self(
            SchemaDocument::integer($document, 'ordinal'),
            $kind,
            $risk,
            SchemaDocument::string($document, 'table'),
            SchemaDocument::string($document, 'subject'),
            SchemaDocument::object($document, 'before', true),
            SchemaDocument::object($document, 'after', true),
            SchemaDocument::boolean($document, 'requires_backfill'),
            SchemaDocument::string($document, 'recovery_implication'),
        );
        $checksum = $document['checksum'] ?? null;
        if ($checksum !== null && (!is_string($checksum) || !hash_equals($operation->checksum(), $checksum))) {
            throw new InvalidBusinessSchema('A persisted schema operation checksum does not match its content.');
        }

        return $operation;
    }

    /**
     * Export the content that defines this operation's identity.
     *
     * The checksum is deliberately excluded, which is what lets it be computed over this array.
     *
     * @return  array<string, mixed>  Ordinal, kind, risk, target, both states, and the recovery facts.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'ordinal' => $this->ordinal,
            'kind' => $this->kind->value,
            'risk' => $this->risk->value,
            'table' => $this->table,
            'subject' => $this->subject,
            'before' => $this->before,
            'after' => $this->after,
            'requires_backfill' => $this->requiresBackfill,
            'recovery_implication' => $this->recoveryImplication,
        ];
    }

    /**
     * Export the operation in the shape written to the step journal.
     *
     * @return  array<string, mixed>  The canonical content plus a `checksum` entry `fromArray()` verifies.
     *
     * @since   2.0.0
     */
    public function persistedArray(): array
    {
        return [...$this->toArray(), 'checksum' => $this->checksum()];
    }

    /**
     * Compute the content address of this operation.
     *
     * @return  string  Lowercase SHA-256 over the canonical JSON encoding of `toArray()`.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        return CanonicalDefinitionJson::checksum($this->toArray());
    }
}
