<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use Kumwe\Reporting\Domain\ReportDefinition;

/**
 * Port through which the App turns policy-filtered report rows into the grouped, computed and ordered result.
 *
 * `ReportService` reads rows through the record seam and hands the complete set to this port together with the
 * signed definition; grouping, the count, sum, average, minimum and maximum aggregates, the formula columns and
 * the stable ordering are the `report-materialization-draft/1` profile that `kumwe/reporting` freezes and the
 * native Engine executes. The App owns authorization, retrieval, projection and delivery around it.
 *
 * @since  2.0.0
 */
interface ReportMaterialization
{
    /**
     * Materialize the declared groups, aggregates, formulas and sorts over the authorized rows.
     *
     * @param   ReportDefinition                           $report  Signed definition whose groups, aggregates,
     *          formulas and sorts are applied.
     * @param   list<array<string, bool|int|string|null>>  $rows    Complete policy-filtered rows, one cell per
     *          declared column alias.
     *
     * @return  list<array<string, bool|int|string|null>>  Result rows keyed by output alias in the order the
     *          definition declares its outputs: group columns or every column, then aggregates, then formulas.
     *
     * @throws  ReportUnavailable  When a row lacks a grouped, aggregated or formula-read cell, a value
     *          contradicts its declared type, or the rows exceed the native materialization budget.
     *
     * @since   2.0.0
     */
    public function materialize(ReportDefinition $report, array $rows): array;
}
