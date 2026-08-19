<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Domain;

use InvalidArgumentException;

/**
 * Declarative binding from a typed report parameter to one query-AST predicate.
 *
 * @since  2.0.0
 */
final readonly class ReportFilterDefinition
{
    /**
     * Declare one root or single-hop relation filter.
     *
     * @param   string                    $fieldPath   Root field or `relationship.field` path.
     * @param   ReportFilterOperator      $operator    Closed operator translated without SQL text.
     * @param   ?string                   $parameter   Parameter handle, null only for null tests.
     * @param   ReportRelationQuantifier  $quantifier  Quantifier used for a relationship path.
     *
     * @throws  InvalidArgumentException  When the path or parameter pairing is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $fieldPath,
        public ReportFilterOperator $operator,
        public ?string $parameter = null,
        public ReportRelationQuantifier $quantifier = ReportRelationQuantifier::Any,
    ) {
        ReportDefinitionGuard::path($fieldPath, 'filter field');
        if ($operator->isNullTest() !== ($parameter === null)) {
            throw new InvalidArgumentException('Only report null filters omit a parameter.');
        }
        if ($parameter !== null) {
            ReportDefinitionGuard::handle($parameter, 'filter parameter');
        }
    }
}
