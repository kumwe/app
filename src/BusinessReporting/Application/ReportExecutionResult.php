<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Application;

use InvalidArgumentException;
use Kumwe\CMS\BusinessReporting\Domain\ReportDrillDownDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportValueType;

/**
 * Bounded report result whose rows contain disclosure-safe scalar output only.
 *
 * @since  2.0.0
 */
final readonly class ReportExecutionResult
{
    /**
     * Policy-filtered report rows in deterministic output order.
     *
     * @var    list<array<string, bool|int|string|null>>
     * @since  2.0.0
     */
    public array $rows;

    /**
     * Output labels keyed by report column identifier.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    public array $labels;

    /**
     * Output value types keyed by report column identifier.
     *
     * @var    array<string, ReportValueType>
     * @since  2.0.0
     */
    public array $types;

    /**
     * Declarative detail links that may be materialized by each delivery adapter.
     *
     * @var    list<ReportDrillDownDefinition>
     * @since  2.0.0
     */
    public array $drillDowns;

    /**
     * Hold one completed bounded result.
     *
     * @param   string                                     $reportIdentifier    Report handle.
     * @param   string                                     $definitionChecksum  Exact definition checksum.
     * @param   string                                     $queryDigest         Canonical parameter and query digest.
     * @param   array<string, string>                      $labels              Output label by alias.
     * @param   array<string, ReportValueType>             $types               Output scalar type by alias.
     * @param   list<array<string, bool|int|string|null>>  $rows                Safe materialized result rows.
     * @param   list<ReportDrillDownDefinition>            $drillDowns          Safe record-detail link declarations.
     *
     * @throws  InvalidArgumentException  When result metadata or rows are malformed.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $reportIdentifier,
        public string $definitionChecksum,
        public string $queryDigest,
        array $labels,
        array $types,
        array $rows,
        array $drillDowns = [],
    ) {
        if (
            preg_match('/^[0-9a-f]{64}$/D', $definitionChecksum) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $queryDigest) !== 1
            || !array_is_list($rows)
        ) {
            throw new InvalidArgumentException('Report result metadata is invalid.');
        }
        if (array_keys($labels) !== array_keys($types)) {
            throw new InvalidArgumentException('Report result labels and types must name identical outputs.');
        }
        foreach ($types as $type) {
            if (!$type instanceof ReportValueType) {
                throw new InvalidArgumentException('A report result output type is invalid.');
            }
        }
        foreach ($drillDowns as $drillDown) {
            if (
                !$drillDown instanceof ReportDrillDownDefinition
                || ($types[$drillDown->recordAlias] ?? null) !== ReportValueType::Identifier
            ) {
                throw new InvalidArgumentException('A report result drill-down is invalid.');
            }
        }
        $normalizedRows = [];
        $outputs = array_fill_keys(array_keys($labels), true);
        foreach ($rows as $row) {
            if (
                !is_array($row)
                || array_diff_key($row, $outputs) !== []
                || array_diff_key($outputs, $row) !== []
            ) {
                throw new InvalidArgumentException('A report result row does not match its declared outputs.');
            }
            $normalizedRow = [];
            foreach ($labels as $alias => $_label) {
                $value = $row[$alias];
                if ($value !== null && !$types[$alias]->accepts($value)) {
                    throw new InvalidArgumentException('A report result row contradicts its declared output type.');
                }
                $normalizedRow[$alias] = $value;
            }
            $normalizedRows[] = $normalizedRow;
        }
        $this->labels = $labels;
        $this->types = $types;
        $this->rows = $normalizedRows;
        $this->drillDowns = array_values($drillDowns);
    }
}
