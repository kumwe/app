<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use InvalidArgumentException;

/**
 * Leaf of a business-record filter tree: one field measured against one literal value.
 *
 * Everything a caller can put in this node is checked while it is built, before any compiler sees it —
 * the field handle against the query identifier grammar, and the value against the closed set of
 * bounded types a query may bind. Floats are excluded there so a stored exact value is never matched
 * against an approximation, and null is excluded because absence is `NullFilter`'s job: a comparison
 * against null is never true in SQL, whatever the caller meant by writing one.
 *
 * @since  2.0.0
 */
final readonly class ComparisonFilter implements RecordFilter
{
    /**
     * Compare one field against one value, validating both before the node exists.
     *
     * @param   string              $field     Handle of the definition field to test; the compiler further
     *          requires it to be filterable and visible to the caller.
     * @param   ComparisonOperator  $operator  Equality or ordering test to apply.
     * @param   mixed               $value     Literal to compare against: a bool, int, string of at most
     *          4096 bytes, or a date-time, decimal, money, quantity or zoned date-time value object. Never
     *          null and never a float.
     *
     * @throws  InvalidArgumentException  When the field handle is not a lowercase query identifier, the value
     *          is null, or the value is of a type or size a query may not bind.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $field,
        public ComparisonOperator $operator,
        public mixed $value,
    ) {
        QueryIdentifier::assertField($field);
        if ($value === null) {
            throw new InvalidArgumentException('Null comparisons require an explicit null filter.');
        }
        QueryValue::assert($value);
    }

    /**
     * Export the comparison in the canonical shape a query digest hashes.
     *
     * The value is canonicalised on the way out, so a value object and the scalar it flattens to produce
     * the same fingerprint for the query that carries them.
     *
     * @return  array{type: string, field: string, operator: string, value: mixed}  The node tagged
     *          `comparison`, the operator's backing value, and the canonicalised literal.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['type' => 'comparison', 'field' => $this->field, 'operator' => $this->operator->value,
            'value' => QueryCanonicalizer::value($this->value)];
    }

    /**
     * Count the predicates a compiler would emit for this node.
     *
     * @return  int  Always one, even for a composite field, whose several columns the compiler compares
     *          as a single test.
     *
     * @since   2.0.0
     */
    public function operationCount(): int
    {
        return 1;
    }

    /**
     * Measure how far this node nests.
     *
     * @return  int  Always one: a comparison is a leaf and carries no children.
     *
     * @since   2.0.0
     */
    public function depth(): int
    {
        return 1;
    }

    /**
     * Measure how many relation hops this node crosses.
     *
     * @return  int  Always zero: a comparison reads the queried record's own field.
     *
     * @since   2.0.0
     */
    public function relationDepth(): int
    {
        return 0;
    }
}
