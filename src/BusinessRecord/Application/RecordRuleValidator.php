<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimal;
use Kumwe\CMS\BusinessRecord\Domain\MoneyValue;
use Kumwe\CMS\BusinessRecord\Domain\QuantityValue;
use Kumwe\CMS\BusinessRecord\Domain\RecordValueGuard;
use Kumwe\CMS\BusinessRecord\Domain\ZonedDateTimeValue;
use Ramsey\Uuid\Uuid;

/**
 * Turns a caller's submitted values into the normalized set a business record is allowed to store.
 *
 * This is where a published definition's write policy is enforced: identity is server-assigned, computed
 * and server-only fields refuse caller values, immutable fields refuse to move after creation,
 * editability conditions gate an update, the required and nullable rules and each field's declared
 * validator list judge single values, and record invariants judge the set. `RecordValueCodec` owns the
 * per-value type conversion this delegates to, so the rules here stay about policy rather than
 * representation. Every breach is collected instead of aborting the pass, and the whole list is raised
 * once as `BusinessRecordValidationFailed`, which is what lets a caller correct a form in one round trip.
 *
 * `BusinessRecordService` calls `create()` and `update()` on the write path, the read repository calls
 * `materialize()` to put virtual computations back onto a decoded row, and the schema repin gateway calls
 * `repin()` to prove a stored row still satisfies a newly published definition before its pin moves.
 *
 * @since  2.0.0
 */
final readonly class RecordRuleValidator
{
    /**
     * Wire the validator to the codec that converts individual field values.
     *
     * @param  RecordValueCodec  $codec  Codec applied to every value the rules accept, and the source of
     *         the type failures reported as `invalid_type` violations.
     *
     * @since  2.0.0
     */
    public function __construct(private RecordValueCodec $codec)
    {
    }

    /**
     * Validate a submitted value set for a new record and return the values ready to encode.
     *
     * Identity is never taken from the input: the definition's identity field is set to `$recordId`
     * whatever the caller sent. An omitted field falls back to its declared default, and an omitted
     * optional field with no default is stored as null. Supplying a computed, server-only, or read-only
     * field is reported rather than quietly ignored, so a caller learns its payload was not honoured.
     *
     * @param   EntityTypeDefinition  $definition      Published definition whose field rules apply.
     * @param   array<string, mixed>  $input           Caller-supplied values keyed by field handle; an
     *          unrecognised handle is reported rather than dropped.
     * @param   string                $siteIdentifier  Site the record belongs to, bound into encrypted
     *          secret values as associated data.
     * @param   string                $recordKey       Internal row key, bound into that same associated
     *          data; equal to the record ID only under the UUID identity strategy.
     * @param   string                $recordId        Public identity written into the identity field.
     *
     * @return  array<string, mixed>  Normalized values by field handle, computed fields and identity
     *          included, ready for `RecordValueCodec::encodeColumns()`.
     *
     * @throws  BusinessRecordValidationFailed  When any field rule or record invariant was breached; it
     *          carries every breach the pass found, not only the first.
     *
     * @since   2.0.0
     */
    public function create(
        EntityTypeDefinition $definition,
        array $input,
        string $siteIdentifier,
        string $recordKey,
        string $recordId,
    ): array {
        $violations = [];
        $fields = $this->fields($definition);
        foreach (array_keys($input) as $handle) {
            if (!isset($fields[$handle])) {
                $violations[] = new ValidationViolation($handle, 'unknown', 'The field is not defined.');
            }
        }

        $values = [];
        foreach ($fields as $field) {
            $isIdentity = in_array($field->type, ['core.uuid', 'core.reference_identity'], true);
            if ($isIdentity) {
                $values[$field->handle] = $recordId;
                continue;
            }
            if ($field->computed || $field->formula !== null) {
                if (array_key_exists($field->handle, $input)) {
                    $violations[] = new ValidationViolation(
                        $field->handle,
                        'read_only',
                        'A computed field cannot be supplied by a caller.',
                    );
                }
                continue;
            }
            if (($field->serverOnly || $field->readOnly) && array_key_exists($field->handle, $input)) {
                $violations[] = new ValidationViolation(
                    $field->handle,
                    'read_only',
                    'A server-only or read-only field cannot be supplied by a caller.',
                );
                continue;
            }
            $present = array_key_exists($field->handle, $input);
            $raw = $present ? $input[$field->handle] : $field->default;
            if (!$present && $raw === null && !$field->required) {
                $values[$field->handle] = null;
                continue;
            }
            $this->normalizeField(
                $field,
                $raw,
                $siteIdentifier,
                $definition->id,
                $recordKey,
                $values,
                $violations,
            );
        }
        $this->compute($definition, $siteIdentifier, $recordKey, $values, $violations);
        $this->validate($definition, $values, $violations);
        $this->throwIfInvalid($violations);

        return $values;
    }

    /**
     * Validate a patch against the record's current values and return the merged, revalidated set.
     *
     * Only the handles present in `$patch` count as the caller's doing; every other value is carried over
     * from `$current` and still re-judged, because a formula or a cross-field invariant may depend on
     * what moved. An identity or immutable-after-create field may appear in the patch, but only when it
     * repeats the value already stored. A field guarded by an editability condition is rejected unless
     * that condition reads true over the record as it stands *before* the patch is applied.
     *
     * @param   EntityTypeDefinition  $definition      Published definition whose field rules apply.
     * @param   array<string, mixed>  $current         Values the stored record holds, already normalized.
     * @param   array<string, mixed>  $patch           Values the caller wants changed, keyed by field
     *          handle; an unrecognised handle is reported rather than dropped.
     * @param   string                $siteIdentifier  Site the record belongs to, bound into encrypted
     *          secret values as associated data.
     * @param   string                $recordKey       Internal row key, bound into that same associated
     *          data.
     * @param   string                $recordId        Public identity of the record; accepted for
     *          symmetry with `create()`, as immutability is judged against `$current` instead.
     *
     * @return  array<string, mixed>  The whole value set after the patch, with every formula field
     *          re-evaluated, ready to become the record's new state.
     *
     * @throws  BusinessRecordValidationFailed  When any field rule or record invariant was breached; it
     *          carries every breach the pass found.
     *
     * @since   2.0.0
     */
    public function update(
        EntityTypeDefinition $definition,
        array $current,
        array $patch,
        string $siteIdentifier,
        string $recordKey,
        string $recordId,
    ): array {
        $violations = [];
        $fields = $this->fields($definition);
        $values = $current;
        foreach ($patch as $handle => $raw) {
            $field = $fields[$handle] ?? null;
            if ($field === null) {
                $violations[] = new ValidationViolation($handle, 'unknown', 'The field is not defined.');
                continue;
            }
            if (
                in_array($field->type, ['core.uuid', 'core.reference_identity'], true)
                || $field->immutableAfterCreate
            ) {
                if (($current[$handle] ?? null) !== $raw) {
                    $violations[] = new ValidationViolation(
                        $handle,
                        'immutable',
                        'An immutable field cannot change after creation.',
                    );
                }
                continue;
            }
            if ($field->serverOnly || $field->readOnly || $field->computed || $field->formula !== null) {
                $violations[] = new ValidationViolation(
                    $handle,
                    'read_only',
                    'A server-only, read-only, or computed field cannot be changed by a caller.',
                );
                continue;
            }
            if ($field->editabilityCondition !== null) {
                try {
                    if ($field->editabilityCondition->evaluate($this->formulaValues($current)) !== true) {
                        $violations[] = new ValidationViolation(
                            $handle,
                            'not_editable',
                            'The field editability condition rejected this change.',
                        );
                        continue;
                    }
                } catch (InvalidArgumentException) {
                    $violations[] = new ValidationViolation(
                        $handle,
                        'condition_failed',
                        'The field editability condition could not be evaluated.',
                    );
                    continue;
                }
            }
            $this->normalizeField(
                $field,
                $raw,
                $siteIdentifier,
                $definition->id,
                $recordKey,
                $values,
                $violations,
            );
        }
        $this->compute($definition, $siteIdentifier, $recordKey, $values, $violations);
        $this->validate($definition, $values, $violations);
        $this->throwIfInvalid($violations);

        return $values;
    }

    /**
     * Reconstitutes virtual formulas after a typed row is decoded.
     *
     * A virtual computation owns no column, so a decoded row arrives without it; this re-evaluates every
     * formula the definition declares and writes each result into the returned set. Unlike the write
     * paths, a formula whose dependencies are absent from the row is skipped silently instead of being
     * reported, so a projection that selected only some columns still materializes what it can.
     *
     * @param   EntityTypeDefinition  $definition      Definition the row was decoded against.
     * @param   array<string, mixed>  $storedValues    Decoded field values keyed by handle.
     * @param   string                $siteIdentifier  Site the record belongs to, bound into secret
     *          associated data when a formula result is normalized.
     * @param   string                $recordKey       Internal row key, bound into that same data.
     *
     * @return  array<string, mixed>  The decoded values with every evaluable formula field written in,
     *          stored computations recomputed rather than trusted.
     *
     * @throws  BusinessRecordValidationFailed  When a formula could not be evaluated or produced a
     *          result the codec rejects for its field.
     *
     * @since   2.0.0
     */
    public function materialize(
        EntityTypeDefinition $definition,
        array $storedValues,
        string $siteIdentifier,
        string $recordKey,
    ): array {
        $violations = [];
        $this->compute($definition, $siteIdentifier, $recordKey, $storedValues, $violations, false);
        $this->throwIfInvalid($violations);

        return $storedValues;
    }

    /**
     * Validates a decoded stored row against a newly published definition before schema repinning.
     * Stored computations are recomputed and all normalizers, validators, and invariants run again.
     *
     * The row is rebuilt rather than patched: every non-formula field is re-normalized through the target
     * definition, so a row the new definition would not accept is caught before its version pin moves.
     * Ordered-line fields are skipped because their contents live in the relationship tables. The identity
     * field is set from `$recordId` whatever the target's normalizers would make of it, and a normalizer
     * that would have moved it is itself reported as an `identity_normalization` breach.
     *
     * @param   EntityTypeDefinition  $definition      Target definition the row is being repinned to.
     * @param   array<string, mixed>  $storedValues    Decoded values of the stored row, keyed by handle;
     *          a handle the target declares but the row lacks is treated as null.
     * @param   string                $siteIdentifier  Site the record belongs to, bound into secret
     *          associated data.
     * @param   string                $recordKey       Internal row key, bound into that same data.
     * @param   string                $recordId        Public identity the row keeps; the identity field
     *          is set to it rather than to whatever normalization produced.
     *
     * @return  array<string, mixed>  Values rebuilt under the target definition, ready to re-encode
     *          against the new schema.
     *
     * @throws  BusinessRecordValidationFailed  When the stored row breaches a field rule or a record
     *          invariant of the target definition.
     *
     * @since   2.0.0
     */
    public function repin(
        EntityTypeDefinition $definition,
        array $storedValues,
        string $siteIdentifier,
        string $recordKey,
        string $recordId,
    ): array {
        $violations = [];
        $values = [];
        foreach ($definition->fields() as $field) {
            if ($field->type === 'core.ordered_lines') {
                continue;
            }
            if (in_array($field->type, ['core.uuid', 'core.reference_identity'], true)) {
                $violationCount = count($violations);
                $this->normalizeField(
                    $field,
                    $recordId,
                    $siteIdentifier,
                    $definition->id,
                    $recordKey,
                    $values,
                    $violations,
                );
                if (count($violations) === $violationCount && $values[$field->handle] !== $recordId) {
                    $violations[] = new ValidationViolation(
                        $field->handle,
                        'identity_normalization',
                        'A target normalizer would change the immutable record identity.',
                    );
                }
                $values[$field->handle] = $recordId;
                continue;
            }
            if ($field->formula !== null) {
                continue;
            }
            $this->normalizeField(
                $field,
                $storedValues[$field->handle] ?? null,
                $siteIdentifier,
                $definition->id,
                $recordKey,
                $values,
                $violations,
            );
        }
        $this->compute($definition, $siteIdentifier, $recordKey, $values, $violations);
        $this->validate($definition, $values, $violations);
        $this->throwIfInvalid($violations);

        return $values;
    }

    /**
     * Index the definition's fields by handle so a submitted key can be resolved in one lookup.
     *
     * @param   EntityTypeDefinition  $definition  Definition whose declared fields are indexed.
     *
     * @return  array<string, FieldDefinition>  Every declared field under its handle, in declaration
     *          order; a handle absent from the map is a field the definition does not declare.
     *
     * @since   2.0.0
     */
    private function fields(EntityTypeDefinition $definition): array
    {
        $fields = [];
        foreach ($definition->fields() as $field) {
            $fields[$field->handle] = $field;
        }

        return $fields;
    }

    /**
     * Run one raw value through the codec, recording either the normalized result or a type breach.
     *
     * A null is stored as null without reaching the codec, which leaves it to the required and nullable
     * rules in `validate()` to decide whether that null is acceptable for the field.
     *
     * @param   FieldDefinition            $field           Field whose type and normalizers apply.
     * @param   mixed                      $raw             Value as submitted, defaulted, or read from
     *          storage.
     * @param   string                     $siteIdentifier  Site bound into secret associated data.
     * @param   string                     $definitionId    Definition ID bound into that same data.
     * @param   string                     $recordKey       Row key bound into that same data.
     * @param   array<string, mixed>       $values          Accumulating value set, written by handle.
     * @param   list<ValidationViolation>  $violations      Accumulating breach list, appended to with an
     *          `invalid_type` entry when the codec rejects the value.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function normalizeField(
        FieldDefinition $field,
        mixed $raw,
        string $siteIdentifier,
        string $definitionId,
        string $recordKey,
        array &$values,
        array &$violations,
    ): void {
        if ($raw === null) {
            $values[$field->handle] = null;
            return;
        }
        try {
            $values[$field->handle] = $this->codec->normalize(
                $field,
                $raw,
                $siteIdentifier,
                $definitionId,
                $recordKey,
            );
        } catch (InvalidArgumentException $exception) {
            $violations[] = new ValidationViolation($field->handle, 'invalid_type', $exception->getMessage());
        }
    }

    /**
     * Evaluate every formula the definition declares and write each result into the value set.
     *
     * Re-evaluating every formula is deterministic and also invalidates every stored computation dependency.
     * Formulas resolve over repeated passes, so one may read another's result; a pass that resolves
     * nothing ends the loop. A formula whose evaluation or normalization fails is reported as
     * `formula_failed` and dropped, while one whose dependencies never arrive is reported as
     * `formula_dependency` only when `$requireAll` is set.
     *
     * @param   EntityTypeDefinition       $definition      Definition supplying the formula fields.
     * @param   string                     $siteIdentifier  Site bound into secret associated data when a
     *          result is normalized.
     * @param   string                     $recordKey       Row key bound into that same data.
     * @param   array<string, mixed>       $values          Value set read for dependencies and written
     *          with each computed result.
     * @param   list<ValidationViolation>  $violations      Accumulating breach list.
     * @param   bool                       $requireAll      Whether a formula left unresolved is a breach;
     *          false on the read path, where a partial projection need not carry every dependency.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function compute(
        EntityTypeDefinition $definition,
        string $siteIdentifier,
        string $recordKey,
        array &$values,
        array &$violations,
        bool $requireAll = true,
    ): void {
        $pending = [];
        foreach ($definition->fields() as $field) {
            if ($field->formula !== null) {
                $pending[$field->handle] = $field;
            }
        }
        for ($pass = 0; $pending !== [] && $pass <= count($pending); ++$pass) {
            $advanced = false;
            foreach ($pending as $handle => $field) {
                foreach ($field->formula?->dependencies() ?? [] as $dependency) {
                    if (!array_key_exists($dependency, $values)) {
                        continue 2;
                    }
                }
                try {
                    $raw = $field->formula?->evaluate($this->formulaValues($values));
                    $values[$handle] = $this->codec->normalize(
                        $field,
                        $raw,
                        $siteIdentifier,
                        $definition->id,
                        $recordKey,
                    );
                    unset($pending[$handle]);
                    $advanced = true;
                } catch (InvalidArgumentException $exception) {
                    $violations[] = new ValidationViolation($handle, 'formula_failed', $exception->getMessage());
                    unset($pending[$handle]);
                }
            }
            if (!$advanced) {
                break;
            }
        }
        if ($requireAll) {
            foreach (array_keys($pending) as $handle) {
                $violations[] = new ValidationViolation(
                    $handle,
                    'formula_dependency',
                    'A stored computation dependency is unavailable.',
                );
            }
        }
    }

    /**
     * Judge every field of the assembled value set, then the invariants that span several of them.
     *
     * A missing value is reported once — `required` when the field demands one, `not_nullable` when the
     * column will not hold null — and that field's own validator list is then skipped, since there is
     * nothing to judge. A present value runs every validator it declares, so one field can contribute
     * several breaches to the same pass.
     *
     * @param   EntityTypeDefinition       $definition  Definition supplying the rules to apply.
     * @param   array<string, mixed>       $values      Normalized value set to judge, keyed by handle.
     * @param   list<ValidationViolation>  $violations  Accumulating breach list.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validate(EntityTypeDefinition $definition, array $values, array &$violations): void
    {
        foreach ($definition->fields() as $field) {
            $value = $values[$field->handle] ?? null;
            if ($field->required && $value === null) {
                $violations[] = new ValidationViolation($field->handle, 'required', 'The field is required.');
                continue;
            }
            if (!$field->nullable && $value === null) {
                $violations[] = new ValidationViolation(
                    $field->handle,
                    'not_nullable',
                    'The field cannot be null.',
                );
                continue;
            }
            if ($value === null) {
                continue;
            }
            foreach ($field->validators as $validator) {
                $violation = $this->validator($field, $value, $validator, $values);
                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        }
        $this->validateInvariants($definition, $values, $violations);
    }

    /**
     * Evaluate the definition's cross-field invariants over the assembled value set.
     *
     * A rule that reads false is reported against its own handle, carrying the definition's operator
     * wording and the code `invariant.<handle>` so a client can tell which rule failed. A rule that
     * cannot be evaluated at all — a dependency the record does not carry, a value contradicting the
     * declared type — becomes an `invariant_invalid` breach instead of escaping as a fault.
     *
     * @param   EntityTypeDefinition       $definition  Definition supplying the invariants.
     * @param   array<string, mixed>       $values      Normalized value set the conditions read.
     * @param   list<ValidationViolation>  $violations  Accumulating breach list.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validateInvariants(
        EntityTypeDefinition $definition,
        array $values,
        array &$violations,
    ): void {
        foreach ($definition->recordInvariants() as $invariant) {
            try {
                $satisfied = $invariant->isSatisfied($this->formulaValues($values));
            } catch (InvalidArgumentException $exception) {
                $violations[] = new ValidationViolation(
                    $invariant->handle,
                    'invariant_invalid',
                    $exception->getMessage(),
                );
                continue;
            }
            if (!$satisfied) {
                $violations[] = new ValidationViolation(
                    $invariant->handle,
                    'invariant.' . $invariant->handle,
                    $invariant->message,
                );
            }
        }
    }

    /**
     * Apply one declared validator rule to one normalized field value.
     *
     * The rule vocabulary is closed. An unnamed rule, an unregistered rule name, or an argument outside
     * the bounds its rule accepts is reported as `validator_invalid` rather than skipped, so a definition
     * declaring a check this build cannot run never passes for lack of enforcement.
     *
     * @param   FieldDefinition       $field      Field the rule is declared on, named in the breach.
     * @param   mixed                 $value      Normalized value to judge; never null, because
     *          `validate()` settles absence before reaching here.
     * @param   array<string, mixed>  $validator  One rule document: `rule` names the check and `value`
     *          carries its argument.
     * @param   array<string, mixed>  $values     The record's whole value set; no rule in the current
     *          vocabulary reads it, since each judges a single value.
     *
     * @return  ?ValidationViolation  The breach, or null when the value satisfies the rule.
     *
     * @since   2.0.0
     */
    private function validator(
        FieldDefinition $field,
        mixed $value,
        array $validator,
        array $values,
    ): ?ValidationViolation {
        $rule = $validator['rule'] ?? null;
        if (!is_string($rule)) {
            return $this->violation($field, 'validator_invalid', 'A validator has no registered rule.');
        }
        try {
            $valid = match ($rule) {
                'min_length' => is_string($value)
                    && mb_strlen($value) >= $this->validatorInt($validator, 'value', 0, 1_000_000),
                'max_length' => is_string($value)
                    && mb_strlen($value) <= $this->validatorInt($validator, 'value', 0, 1_000_000),
                'pattern' => is_string($value) && $this->pattern($value, $validator),
                'min' => $this->compare($value, $this->validatorScalar($validator, 'value'), $field) >= 0,
                'max' => $this->compare($value, $this->validatorScalar($validator, 'value'), $field) <= 0,
                'one_of' => in_array(
                    RecordValueGuard::canonical($value),
                    $this->validatorList($validator, 'value'),
                    true,
                ),
                'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                'url' => is_string($value) && $this->validHttpUrl($value),
                'uuid' => is_string($value) && Uuid::isValid($value),
                'integer' => is_int($value),
                'decimal' => $value instanceof ExactDecimal,
                default => throw new InvalidArgumentException('An unregistered validator rule was requested.'),
            };
        } catch (InvalidArgumentException $exception) {
            return $this->violation($field, 'validator_invalid', $exception->getMessage());
        }
        if ($valid) {
            return null;
        }
        return $this->violation($field, $rule, 'The field failed its ' . $rule . ' validation rule.');
    }

    /**
     * Match a value against a declared regular expression under bounded backtracking.
     *
     * The declared expression is wrapped in `~…~uD` with PCRE match and depth limits, and any `~` inside
     * it is escaped, so a definition cannot smuggle in its own delimiters or modifiers. An expression
     * that fails to compile, or that exhausts the limits, is a validator failure rather than a non-match,
     * which keeps an untrusted definition from turning a catastrophic pattern into a silent pass.
     *
     * @param   string                $value      Normalized string to test.
     * @param   array<string, mixed>  $validator  Rule document whose `value` holds the expression.
     *
     * @return  bool  Whether the subject matched the declared expression.
     *
     * @throws  InvalidArgumentException  When the expression is absent, empty, longer than 512 bytes, or
     *          failed to run within the configured limits.
     *
     * @since   2.0.0
     */
    private function pattern(string $value, array $validator): bool
    {
        $expression = $validator['value'] ?? null;
        if (!is_string($expression) || $expression === '' || strlen($expression) > 512) {
            throw new InvalidArgumentException('A pattern validator requires a bounded expression.');
        }
        $pattern = '~(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=1000)(?:' . str_replace('~', '\\~', $expression) . ')~uD';
        $result = preg_match($pattern, $value);
        if ($result === false) {
            throw new InvalidArgumentException('A pattern validator is invalid or exceeded its runtime limit.');
        }

        return $result === 1;
    }

    /**
     * Order a normalized value against a validator bound so `min` and `max` can be judged.
     *
     * An exact decimal is compared through the codec: the declared bound is normalized against the same
     * field first, which is what keeps a bound of `'0.00'` meaningful for a DECIMAL column instead of
     * being compared as text. An integer value accepts only a bound spelled as a canonical integer
     * string, so a bound declared as a JSON number is refused; two strings compare byte-wise. Every
     * other pairing is refused rather than coerced into one.
     *
     * @param   mixed            $left   Normalized field value being judged.
     * @param   mixed            $right  Bound as the validator declared it.
     * @param   FieldDefinition  $field  Field supplying the type the bound is normalized against.
     *
     * @return  int  Negative, zero or positive as $left sorts before, with, or after $right.
     *
     * @throws  InvalidArgumentException  When the bound cannot be normalized to the field's type, or the
     *          two values have no comparable representation.
     *
     * @since   2.0.0
     */
    private function compare(mixed $left, mixed $right, FieldDefinition $field): int
    {
        if ($left instanceof ExactDecimal) {
            $normalized = $this->codec->normalize($field, $right, '', '', '');
            if (!$normalized instanceof ExactDecimal) {
                throw new InvalidArgumentException('An exact validator comparison is incompatible.');
            }
            return $left->compare($normalized);
        }
        if (is_int($left) && is_string($right) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $right) === 1) {
            $integer = filter_var($right, FILTER_VALIDATE_INT);
            if (is_int($integer)) {
                return $left <=> $integer;
            }
        }
        if (is_string($left) && is_string($right)) {
            return $left <=> $right;
        }
        throw new InvalidArgumentException('A range validator value is incompatible with its field.');
    }

    /**
     * Read a bounded integer argument out of a validator rule document.
     *
     * @param   array<string, mixed>  $validator  Rule document the argument is read from.
     * @param   string                $key        Argument name, `value` for every current rule.
     * @param   int                   $minimum    Smallest argument the calling rule can act on.
     * @param   int                   $maximum    Largest argument the calling rule can act on.
     *
     * @return  int  The declared argument, once proven an integer inside the bound.
     *
     * @throws  InvalidArgumentException  When the argument is absent, not an integer, or outside the
     *          bound the calling rule allows.
     *
     * @since   2.0.0
     */
    private function validatorInt(array $validator, string $key, int $minimum, int $maximum): int
    {
        $value = $validator[$key] ?? null;
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('A validator integer parameter is outside its safe bound.');
        }

        return $value;
    }

    /**
     * Read the comparison bound of a `min` or `max` rule out of its document.
     *
     * A float is refused along with every non-scalar, because a business record never holds one and a
     * bound spelled as a float could not be compared exactly against a stored decimal.
     *
     * @param   array<string, mixed>  $validator  Rule document the bound is read from.
     * @param   string                $key        Argument name, `value` for every current rule.
     *
     * @return  bool|int|string|null  The declared bound; null both when the key is absent and when the
     *          definition declared null, which `compare()` then refuses as incomparable.
     *
     * @throws  InvalidArgumentException  When the bound is a float, or any other non-scalar value.
     *
     * @since   2.0.0
     */
    private function validatorScalar(array $validator, string $key): bool|int|string|null
    {
        $value = $validator[$key] ?? null;
        if (is_float($value) || (!is_scalar($value) && $value !== null)) {
            throw new InvalidArgumentException('A validator scalar parameter is invalid.');
        }

        return $value;
    }

    /**
     * Read the bounded option set a `one_of` rule admits.
     *
     * @param   array<string, mixed>  $validator  Rule document the option set is read from.
     * @param   string                $key        Argument name, `value` for every current rule.
     *
     * @return  list<bool|int|string|null>  The declared options in document order, each already proven
     *          scalar or null so it can be matched against a canonicalized field value.
     *
     * @throws  InvalidArgumentException  When the options are absent, not a list, empty, longer than 256
     *          entries, or hold a float or any other non-scalar entry.
     *
     * @since   2.0.0
     */
    private function validatorList(array $validator, string $key): array
    {
        $values = $validator[$key] ?? null;
        if (!is_array($values) || !array_is_list($values) || $values === [] || count($values) > 256) {
            throw new InvalidArgumentException('A validator list parameter is empty or unbounded.');
        }
        $result = [];
        foreach ($values as $value) {
            if (is_float($value) || (!is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException('A validator list contains an invalid value.');
            }
            $result[] = $value;
        }

        return $result;
    }

    /**
     * Flatten a record's values into the scalar map an expression can be evaluated against.
     *
     * Each domain value becomes the spelling a formula reads — an exact decimal its canonical literal, a
     * date-time an ISO-8601 string with offset, a zoned date-time its UTC instant — while a composite
     * such as money or a quantity, and anything else with no scalar spelling, collapses to null, so a
     * formula naming one sees a missing value rather than an object it cannot compute over.
     *
     * @param   array<string, mixed>  $values  Normalized record values keyed by field handle.
     *
     * @return  array<string, bool|float|int|string|null>  The same handles carrying scalar-only values,
     *          in the shape `Expression::evaluate()` and `RecordInvariantDefinition::isSatisfied()` read.
     *
     * @since   2.0.0
     */
    private function formulaValues(array $values): array
    {
        $result = [];
        foreach ($values as $handle => $value) {
            $result[$handle] = match (true) {
                $value instanceof ExactDecimal => $value->value(),
                $value instanceof DateTimeImmutable => $value->format('Y-m-d\TH:i:s.uP'),
                $value instanceof ZonedDateTimeValue => $value->instant->format('Y-m-d\TH:i:s.u\Z'),
                $value instanceof MoneyValue, $value instanceof QuantityValue => null,
                is_scalar($value), $value === null => $value,
                default => null,
            };
        }

        return $result;
    }

    /**
     * Build a violation addressed to a field, taking the handle from the field itself.
     *
     * @param   FieldDefinition  $field    Field the breach is reported against.
     * @param   string           $code     Stable token naming the rule that failed.
     * @param   string           $message  Operator-facing sentence explaining the failure.
     *
     * @return  ValidationViolation  The breach, ready to append to the pass's list.
     *
     * @since   2.0.0
     */
    private function violation(FieldDefinition $field, string $code, string $message): ValidationViolation
    {
        return new ValidationViolation($field->handle, $code, $message);
    }

    /**
     * Decide whether a value is an absolute HTTP or HTTPS URL.
     *
     * The scheme is checked separately from `FILTER_VALIDATE_URL`, which also accepts `mailto:`, `ftp:`
     * and other schemes a `url` rule is not meant to let through.
     *
     * @param   string  $value  Normalized string to test.
     *
     * @return  bool  True only for a syntactically valid URL whose scheme is http or https.
     *
     * @since   2.0.0
     */
    private function validHttpUrl(string $value): bool
    {
        $parts = parse_url($value);
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true);
    }

    /**
     * Close a validation pass, raising the collected breaches when the pass found any.
     *
     * @param   list<ValidationViolation>  $violations  Every breach the pass collected, in the order it
     *          discovered them.
     *
     * @return  void
     *
     * @throws  BusinessRecordValidationFailed  When the list is not empty.
     *
     * @since   2.0.0
     */
    private function throwIfInvalid(array $violations): void
    {
        if ($violations !== []) {
            throw new BusinessRecordValidationFailed($violations);
        }
    }
}
