<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

/**
 * Predicate that holds when a record's related records satisfy a filter of their own.
 *
 * This is the only way a browse reaches past the table it is querying. It compiles to a correlated
 * `EXISTS` over the related table — reached by a direct column, a junction table, an owned-line table
 * or the canonical inverse, whichever the installed schema provides — rather than to a join, so a
 * record is still returned at most once however many of its relations match. The nested filter is
 * compiled against the target definition, resolved and fenced exactly like the queried one, so its
 * handles are the target's own and only related records inside the request scope that are not
 * soft-deleted are visible to it. Each hop costs another correlated subquery, which is why hops are
 * counted apart from nesting depth and `RecordQuerySpecification` allows only two of them.
 *
 * @since  2.0.0
 */
final readonly class RelationFilter implements RecordFilter
{
    /**
     * Capture one relationship traversal.
     *
     * @param   string              $relationship  Handle of the relationship to traverse, as declared
     *          on the definition being queried.
     * @param   RelationQuantifier  $quantifier    How many of the related records must satisfy the
     *          nested filter.
     * @param   RecordFilter        $target        Predicate evaluated against a related record, written
     *          in the target definition's own field handles.
     *
     * @throws  \InvalidArgumentException  When the relationship handle is not a valid query identifier.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $relationship,
        public RelationQuantifier $quantifier,
        public RecordFilter $target,
    ) {
        QueryIdentifier::assertField($relationship);
    }

    /**
     * Reduce the traversal and the filter it nests to canonical array form.
     *
     * @return  array{type: string, relationship: string, quantifier: string, target: array<string, mixed>}
     *          A `relation` node carrying the relationship handle, the quantifier's backing value, and
     *          the nested filter's own array form.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['type' => 'relation', 'relationship' => $this->relationship,
            'quantifier' => $this->quantifier->value, 'target' => $this->target->toArray()];
    }

    /**
     * Count this traversal together with every node of the filter it applies to related records.
     *
     * @return  int  One for the hop itself plus the nested filter's own operation count.
     *
     * @since   2.0.0
     */
    public function operationCount(): int
    {
        return 1 + $this->target->operationCount();
    }

    /**
     * Report the nesting depth of this traversal, counting the hop as one level.
     *
     * @return  int  One plus the deepest nesting reached inside the nested filter.
     *
     * @since   2.0.0
     */
    public function depth(): int
    {
        return 1 + $this->target->depth();
    }

    /**
     * Report how many relationship hops this branch crosses, this traversal included.
     *
     * @return  int  One plus any hops the nested filter makes; `RecordQuerySpecification` refuses a
     *          filter reporting more than two.
     *
     * @since   2.0.0
     */
    public function relationDepth(): int
    {
        return 1 + $this->target->relationDepth();
    }
}
