<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Query;

use InvalidArgumentException;

/**
 * Branch node of a business-record filter tree: an AND, OR or NOT group over nested filters.
 *
 * This is the only filter that composes others, so it is where a query's shape is bounded. Fan-out is
 * capped at sixteen children per group and a `Not` group is held to exactly one, while the three
 * traversal methods report the cost of the whole subtree to `RecordQuerySpecification`, which refuses
 * a filter deeper than eight levels, nested through more than two relations, or worth more than
 * sixty-four operations. The children are re-indexed on construction, so a group is always a list and
 * always compiles in the order the caller assembled it.
 *
 * @since  2.0.0
 */
final readonly class BooleanFilter implements RecordFilter
{
    /**
     * Filters this group combines, re-indexed from zero in the order the caller supplied.
     *
     * @var    non-empty-list<RecordFilter>
     * @since  2.0.0
     */
    public array $children;

    /**
     * Group filters under one boolean operator, proving the group's arity first.
     *
     * @param   BooleanOperator               $operator  How the children combine: all, any, or the negation
     *          of the single child.
     * @param   non-empty-list<RecordFilter>  $children  Nested filters to combine, between one and sixteen
     *          of them, and exactly one under `Not`.
     *
     * @throws  InvalidArgumentException  When the group has no children, more than sixteen of them, or is a
     *          `Not` group with other than exactly one child.
     *
     * @since   2.0.0
     */
    public function __construct(public BooleanOperator $operator, array $children)
    {
        if ($children === [] || count($children) > 16) {
            throw new InvalidArgumentException('A boolean query group requires between 1 and 16 children.');
        }
        if ($operator === BooleanOperator::Not && count($children) !== 1) {
            throw new InvalidArgumentException('A boolean NOT query group requires exactly one child.');
        }
        $this->children = array_values($children);
    }

    /**
     * Export the group, and everything under it, in the canonical shape a query digest hashes.
     *
     * Children keep their construction order, so two queries that differ only in how their groups were
     * assembled fingerprint differently and cannot page through each other's cursors.
     *
     * @return  array{type: string, operator: string, children: list<array<string, mixed>>}  The group
     *          tagged `boolean`, its operator's backing value, and each child exported the same way.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['type' => 'boolean', 'operator' => $this->operator->value,
            'children' => array_map(static fn (RecordFilter $filter): array => $filter->toArray(), $this->children)];
    }

    /**
     * Count the predicates a compiler would emit for this subtree.
     *
     * @return  int  This group plus the count of every filter beneath it, which a specification caps at 64.
     *
     * @since   2.0.0
     */
    public function operationCount(): int
    {
        return 1 + array_sum(array_map(
            static fn (RecordFilter $filter): int => $filter->operationCount(),
            $this->children,
        ));
    }

    /**
     * Measure how far the deepest branch of this subtree nests.
     *
     * @return  int  One level for this group plus the depth of its deepest child, capped at 8 by a
     *          specification.
     *
     * @since   2.0.0
     */
    public function depth(): int
    {
        return 1 + max(array_map(static fn (RecordFilter $filter): int => $filter->depth(), $this->children));
    }

    /**
     * Measure how many relation hops the deepest branch of this subtree crosses.
     *
     * @return  int  The largest relation depth among the children; grouping adds no hop of its own, so a
     *          subtree of plain field filters reports zero.
     *
     * @since   2.0.0
     */
    public function relationDepth(): int
    {
        return max(array_map(static fn (RecordFilter $filter): int => $filter->relationDepth(), $this->children));
    }
}
