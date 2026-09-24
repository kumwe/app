<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Application;

use Kumwe\BusinessDefinition\Domain\Expression;
use Kumwe\BusinessDefinition\Domain\InvalidBusinessDefinition;

/**
 * Port through which the App evaluates a business-definition condition or formula against record values.
 *
 * Record validation, action preconditions, field visibility and editability, schema backfills and transforms
 * all hand a parsed `Expression` and the scalar values they gathered to this port and read back one value per
 * document. The semantics belong to the `formula-draft/1` profile that `kumwe/business-definition` freezes in
 * its corpus and the native Engine executes; the App owns only the gathering of values, the marshalling of one
 * document per evaluation and the mapping of a refusal onto the exception its callers already turn into a
 * validation violation. A caller that needs several documents judged by one expression uses the batch form so
 * the compiled plan is executed once for the whole set.
 *
 * @phpstan-type FormulaLines array<string, list<array<string, scalar|null>>>
 * @phpstan-type FormulaDocument array{fields: array<string, scalar|null>, lines: FormulaLines}
 *
 * @since  2.0.0
 */
interface FormulaEvaluation
{
    /**
     * Evaluate one expression against one document of field values and owned-line collections.
     *
     * @param   Expression                                       $expression  Parsed tree to evaluate.
     * @param   array<string, scalar|null>                       $fields      Values for the handles the tree
     *          reads, keyed by field handle; exact decimals as canonical strings, never floats.
     * @param   array<string, list<array<string, scalar|null>>>  $lines       Whole owned-line collections keyed
     *          by relationship handle, for the aggregation leaves the tree carries.
     *
     * @return  mixed  Result in the node's declared type: null, a boolean, an integer, or a string that carries
     *          a decimal, text, date, time or date-time value.
     *
     * @throws  InvalidBusinessDefinition  When a dependency or owned-line collection is absent, a supplied
     *          value is a float, or the Engine refuses the evaluation.
     *
     * @since   2.0.0
     */
    public function evaluate(Expression $expression, array $fields, array $lines = []): mixed;

    /**
     * Evaluate one expression against several documents and return one result per document, in order.
     *
     * @param   Expression             $expression  Parsed tree to evaluate once for the whole set.
     * @param   list<FormulaDocument>  $documents   Field values and owned-line collections of each document,
     *          in the order results are wanted; an empty list evaluates nothing.
     *
     * @return  list<mixed>  One result per document in the same order, each shaped as `evaluate()` returns.
     *
     * @throws  InvalidBusinessDefinition  When any document is refused for the reasons `evaluate()` names.
     *
     * @since   2.0.0
     */
    public function evaluateAll(Expression $expression, array $documents): array;
}
