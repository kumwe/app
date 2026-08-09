<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

/**
 * A named rule spanning several fields of a record, carried inside the entity definition.
 *
 * Field-level rules judge one value at a time; an invariant is how a definition states a rule that only
 * makes sense across values — an end date after its start, a total agreeing with its lines.
 * `RecordRuleValidator` evaluates every invariant on create and update and reports a failing one as a
 * violation keyed by this handle carrying this message, so the operator-facing wording lives in the
 * definition rather than in the runtime. The condition is a typed `Expression`, never executable code,
 * which is what allows an untrusted definition to declare a rule at all.
 *
 * @since  2.0.0
 */
final readonly class RecordInvariantDefinition
{
    /**
     * Capture an invariant and reject one that could never be evaluated as a rule.
     *
     * @param   string      $handle     Stable snake_case name reported with the violation.
     * @param   string      $message    Operator-facing text shown when the rule fails, up to 500 bytes.
     * @param   Expression  $condition  Boolean-typed condition read over the record's field values.
     *
     * @throws  InvalidBusinessDefinition  When the handle or message is malformed, or the condition is
     *          not boolean-typed.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $handle,
        public string $message,
        public Expression $condition,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
            throw new InvalidBusinessDefinition('A record invariant handle is invalid.');
        }
        if ($message === '' || strlen($message) > 500) {
            throw new InvalidBusinessDefinition('A record invariant requires a bounded message.');
        }
        if ($condition->type !== 'boolean') {
            throw new InvalidBusinessDefinition('A record invariant condition must produce boolean.');
        }
    }

    /**
     * Rebuild an invariant from the canonical document `toArray()` writes.
     *
     * An unrecognised key is refused rather than dropped, so an invariant exported by a later version is
     * never silently imported with part of its meaning missing.
     *
     * @param   array<string, mixed>  $document  Canonical invariant document, keyed as it is stored.
     *
     * @return  self  The invariant, with every construction rule already applied.
     *
     * @throws  InvalidBusinessDefinition  When the document carries an unknown key, a member of the
     *          wrong shape, or a condition that fails to parse.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        if (array_diff(array_keys($document), ['handle', 'message', 'condition']) !== []) {
            throw new InvalidBusinessDefinition('A record invariant contains an unknown property.');
        }
        $handle = $document['handle'] ?? null;
        $message = $document['message'] ?? null;
        $condition = $document['condition'] ?? null;
        if (!is_string($handle) || !is_string($message) || !is_array($condition) || array_is_list($condition)) {
            throw new InvalidBusinessDefinition('A record invariant has an invalid shape.');
        }

        /** @var array<string, mixed> $condition */
        return new self(trim($handle), trim($message), Expression::fromArray($condition));
    }

    /**
     * Evaluate the condition against one record's values.
     *
     * The caller supplies every field the condition depends on; a missing dependency is an error rather
     * than a false result, so a rule is never quietly reported as passing on incomplete input.
     *
     * @param   array<string, scalar|null>  $fields  Record values the condition reads, keyed by field
     *          handle.
     *
     * @return  bool  True when the record honours the invariant.
     *
     * @throws  InvalidBusinessDefinition  When a dependency is absent, a value contradicts its declared
     *          type, or the condition yields a non-boolean result.
     *
     * @since   2.0.0
     */
    public function isSatisfied(array $fields): bool
    {
        $result = $this->condition->evaluate($fields);
        if (!is_bool($result)) {
            throw new InvalidBusinessDefinition('A record invariant produced a non-boolean result.');
        }

        return $result;
    }

    /**
     * Export the invariant as the document the definition checksum is taken over.
     *
     * @return  array{handle: string, message: string, condition: array<string, mixed>}  The invariant
     *          with its condition rendered as a nested expression document.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'message' => $this->message,
            'condition' => $this->condition->toArray(),
        ];
    }
}
