<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Application\FormulaEvaluation;
use Kumwe\BusinessDefinition\Domain\EntityTypeDefinition;

/**
 * Resolves definition-level read visibility against one complete normalized record value set.
 *
 * Static `readVisible` and dynamic visibility conditions are one application rule. Keeping their evaluation
 * here lets direct records and owned-line projections make the same fail-closed decision without moving
 * expression policy into an HTTP adapter or persistence-specific template. The conditions themselves are
 * judged by the formula port, so the read side and the write side agree on every verdict.
 *
 * @since  2.0.0
 */
final readonly class RecordFieldVisibility
{
    /**
     * Bind the visibility rule to the port that judges each field's condition.
     *
     * @param  FormulaEvaluation  $formulas  Port evaluating visibility conditions over the record's values.
     *
     * @since  2.0.0
     */
    public function __construct(private FormulaEvaluation $formulas)
    {
    }

    /**
     * Index the fields whose values may be disclosed for this record.
     *
     * A missing, invalid, or false condition hides its field. Conditions evaluate over the complete raw value
     * set before any projection, sensitivity redaction, or reference resolution can remove a dependency.
     *
     * @param   EntityTypeDefinition  $definition  Pinned definition supplying field visibility rules.
     * @param   array<string, mixed>  $values      Complete normalized record values keyed by field handle.
     *
     * @return  array<string, true>  Visible field handles as keys, each mapped to true.
     *
     * @since   2.0.0
     */
    public function fields(EntityTypeDefinition $definition, array $values): array
    {
        $visible = [];
        $conditionValues = RecordExpressionValues::from($values);
        foreach ($definition->fields() as $field) {
            if (!$field->readVisible) {
                continue;
            }
            if ($field->visibilityCondition !== null) {
                try {
                    if ($this->formulas->evaluate($field->visibilityCondition, $conditionValues) !== true) {
                        continue;
                    }
                } catch (InvalidArgumentException) {
                    continue;
                }
            }
            $visible[$field->handle] = true;
        }

        return $visible;
    }
}
