<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;

/**
 * Immutable runtime index of reconciled report contributions.
 *
 * @since  2.0.0
 */
final readonly class ReportDefinitionRegistry
{
    /**
     * Active report definitions keyed by their stable identifiers.
     *
     * @var    array<string, ReportDefinition>
     * @since  2.0.0
     */
    private array $reports;

    /**
     * Index definitions and refuse ambiguity.
     *
     * @param   list<ReportDefinition>  $reports  Active reconciled contributions.
     *
     * @throws  InvalidArgumentException  When a member is invalid or an identifier is duplicated.
     *
     * @since   2.0.0
     */
    public function __construct(array $reports)
    {
        if (count($reports) > 256) {
            throw new InvalidArgumentException('The active report registry exceeds its safe bound.');
        }
        $indexed = [];
        foreach ($reports as $report) {
            if (!$report instanceof ReportDefinition || isset($indexed[$report->identifier()])) {
                throw new InvalidArgumentException('A report registry member is invalid or duplicated.');
            }
            $indexed[$report->identifier()] = $report;
        }
        ksort($indexed, SORT_STRING);
        $this->reports = $indexed;
    }

    /**
     * Resolve an active report without revealing why another identifier is unavailable.
     *
     * @param   string  $identifier  Namespaced report handle.
     *
     * @return  ReportDefinition  Exact immutable contribution.
     *
     * @throws  ReportUnavailable  When no active report has the handle.
     *
     * @since   2.0.0
     */
    public function get(string $identifier): ReportDefinition
    {
        return $this->reports[$identifier] ?? throw new ReportUnavailable('The report is unavailable.');
    }

    /**
     * Return all active reports in stable identifier order.
     *
     * @return  list<ReportDefinition>  Reconciled definitions.
     *
     * @since   2.0.0
     */
    public function all(): array
    {
        return array_values($this->reports);
    }
}
