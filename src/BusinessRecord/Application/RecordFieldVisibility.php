<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;

/**
 * Resolves definition-level read visibility against one complete normalized record value set.
 *
 * Static `readVisible` and dynamic visibility conditions are one application rule. Keeping their evaluation
 * here lets direct records and owned-line projections make the same fail-closed decision without moving
 * expression policy into an HTTP adapter or persistence-specific template.
 *
 * @since  2.0.0
 */
final class RecordFieldVisibility
{
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
    public static function fields(EntityTypeDefinition $definition, array $values): array
    {
        $visible = [];
        $conditionValues = RecordExpressionValues::from($values);
        foreach ($definition->fields() as $field) {
            if (!$field->readVisible) {
                continue;
            }
            if ($field->visibilityCondition !== null) {
                try {
                    if ($field->visibilityCondition->evaluate($conditionValues) !== true) {
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

    /**
     * Static utility; instances would carry no state.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
