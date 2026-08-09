<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

/**
 * One node of the bounded predicate tree a business-record browse is allowed to express.
 *
 * Browse accepts no SQL and no expression string: a caller assembles comparison, set, null, text,
 * boolean and relation nodes, and `RecordQuerySpecification` admits the tree only after asking it how
 * large it is. That is why every node reports its own operation count, nesting depth and relation-hop
 * depth — the bounds are settled at construction, before `DoctrineBusinessRecordQueryCompiler` resolves
 * a single handle against the pinned definition. Implementations are immutable, validate their field
 * handles and values as they are built, and name logical handles only, never a physical column.
 *
 * @since  2.0.0
 */
interface RecordFilter
{
    /**
     * Reduce this node and everything beneath it to its canonical array form.
     *
     * The `type` key names the node kind so the tree survives encoding without its class names. This is
     * also the form `RecordQuerySpecification::digest()` hashes, so two filters differing in any operand
     * fingerprint differently and their page cursors cannot be interchanged.
     *
     * @return  array<string, mixed>  The node keyed by `type`, alongside that kind's own operands.
     *
     * @since   2.0.0
     */
    public function toArray(): array;

    /**
     * Count the predicate operations this node contributes, its whole subtree included.
     *
     * @return  int  Number of nodes from here down, at least 1; `RecordQuerySpecification` refuses a
     *          filter of more than 64, and the compiler counts again as it walks the tree.
     *
     * @since   2.0.0
     */
    public function operationCount(): int;

    /**
     * Report how deeply this node nests, counting itself as one level.
     *
     * @return  int  Longest chain of nested nodes from here down, at least 1; capped at 8 by
     *          `RecordQuerySpecification`.
     *
     * @since   2.0.0
     */
    public function depth(): int;

    /**
     * Report how many relationship hops the deepest branch below this node crosses.
     *
     * Hops are counted apart from `depth()` because each one compiles to a correlated `EXISTS` against
     * another table, which costs far more than another conjunct on the queried one.
     *
     * @return  int  Relationship traversals on the longest branch, this node included; 0 for a node
     *          that stays on the queried table, and at most 2 once a specification has accepted it.
     *
     * @since   2.0.0
     */
    public function relationDepth(): int;
}
