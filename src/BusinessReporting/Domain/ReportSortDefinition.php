<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Domain;

/**
 * One ordering key applied to bounded materialized report rows.
 *
 * @since  2.0.0
 */
final readonly class ReportSortDefinition
{
    /**
     * Declare one output sort.
     *
     * @param  string               $outputAlias  Column, aggregate or formula alias.
     * @param  ReportSortDirection  $direction    Direction of the comparison.
     * @param  bool                 $nullsLast    Whether absent values sort after present values.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $outputAlias,
        public ReportSortDirection $direction = ReportSortDirection::Ascending,
        public bool $nullsLast = true,
    ) {
        ReportDefinitionGuard::handle($outputAlias, 'sort output');
    }
}
