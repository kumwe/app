<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Query;

/**
 * Leaf of a business-record filter tree that tests whether a field holds a value at all.
 *
 * This is deliberately a node of its own rather than a comparison against null, because SQL answers no
 * equality test against null the way a caller expects — which is also why `ComparisonFilter` refuses a
 * null literal and points here. The compiler renders the node as `IS NULL`, or `IS NOT NULL`, over
 * every physical column the field occupies and joins those tests with `AND`, so a field stored across
 * several columns is empty only when every one of its columns is null, and filled only when none is.
 *
 * @since  2.0.0
 */
final readonly class NullFilter implements RecordFilter
{
    /**
     * Test one field for the presence or absence of a stored value.
     *
     * @param   string  $field   Handle of the definition field to test; the compiler further requires it
     *          to be filterable and visible to the caller.
     * @param   bool    $isNull  True to match records where the field holds no value, false to match those
     *          where it holds one.
     *
     * @throws  \InvalidArgumentException  When the field handle is not a lowercase query identifier.
     *
     * @since   2.0.0
     */
    public function __construct(public string $field, public bool $isNull = true)
    {
        QueryIdentifier::assertField($field);
    }

    /**
     * Export the test in the canonical shape a query digest hashes.
     *
     * @return  array{type: string, field: string, is_null: bool}  The node tagged `null`, the field
     *          handle, and which way round the test runs.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['type' => 'null', 'field' => $this->field, 'is_null' => $this->isNull];
    }

    /**
     * Count the predicates a compiler would emit for this node.
     *
     * @return  int  Always one, whatever the field's physical column count, since the per-column null
     *          tests of a composite field are budgeted as a single test.
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
     * @return  int  Always one: a null test is a leaf and carries no children.
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
     * @return  int  Always zero: a null test reads the queried record's own field.
     *
     * @since   2.0.0
     */
    public function relationDepth(): int
    {
        return 0;
    }
}
