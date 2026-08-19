<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Query;

use InvalidArgumentException;

/**
 * Leaf of a business-record filter tree that tests one field against a bounded set of literal values.
 *
 * This is how a browse says "any of these" without paying for an OR group per member: the whole set
 * compiles to a single `IN` list and costs one operation against the query's budget. Every member is
 * checked as the node is built, against the same closed set of bounded types a comparison may bind and
 * never null, since absence is `NullFilter`'s job; the list is capped at a hundred so a caller cannot
 * push an unbounded `IN` into a prepared statement. A negated set is spelled with a `CASE` expression
 * rather than SQL `NOT IN`, so a record whose column is null counts as being outside the set instead of
 * silently dropping out of the result.
 *
 * @since  2.0.0
 */
final readonly class SetFilter implements RecordFilter
{
    /**
     * Members the field is tested against, re-indexed from zero and held exactly as the caller passed them.
     *
     * @var    non-empty-list<mixed>
     * @since  2.0.0
     */
    public array $values;

    /**
     * Test one field for membership of a set, proving the field handle and every member first.
     *
     * @param   string                 $field    Handle of the definition field to test; the compiler further
     *          requires it to be filterable, visible to the caller, and stored in a single column the engines
     *          all compare for equality.
     * @param   non-empty-list<mixed>  $values   Members to match against: 1 to 100 bools, ints, strings of at
     *          most 4096 bytes, or date-time, decimal, money, quantity and zoned date-time value objects.
     *          Duplicates are kept as given.
     * @param   bool                   $negated  True to match records whose field lies outside the set, one
     *          holding no value at all included.
     *
     * @throws  InvalidArgumentException  When the field handle is not a lowercase query identifier, the set is
     *          empty or holds more than 100 members, or a member is null, a float, or of a type or size a query
     *          may not bind.
     *
     * @since   2.0.0
     */
    public function __construct(public string $field, array $values, public bool $negated = false)
    {
        QueryIdentifier::assertField($field);
        if ($values === [] || count($values) > 100) {
            throw new InvalidArgumentException('A set filter requires between 1 and 100 values.');
        }
        foreach ($values as $value) {
            if ($value === null) {
                throw new InvalidArgumentException('Set filters cannot contain null; use an explicit null filter.');
            }
            QueryValue::assert($value);
        }
        $this->values = array_values($values);
    }

    /**
     * Export the membership test in the canonical shape a query digest hashes.
     *
     * Members are canonicalised on the way out but keep the order they were given in, so two callers naming
     * the same values in a different order fingerprint differently and cannot share a page cursor.
     *
     * @return  array{type: string, field: string, negated: bool, values: list<mixed>}  The node tagged `set`,
     *          the field handle, which way round the test runs, and the canonicalised members; the member
     *          list is never empty.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['type' => 'set', 'field' => $this->field, 'negated' => $this->negated,
            'values' => array_map(QueryCanonicalizer::value(...), $this->values)];
    }

    /**
     * Count the predicates a compiler would emit for this node.
     *
     * @return  int  Always one, however many members the set carries: the whole `IN` list is budgeted as a
     *          single test.
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
     * @return  int  Always one: a set test is a leaf and carries no children.
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
     * @return  int  Always zero: a set test reads the queried record's own field.
     *
     * @since   2.0.0
     */
    public function relationDepth(): int
    {
        return 0;
    }
}
