<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;

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
     * Build the immutable report index with an optional live extension-generation authority.
     *
     * The container supplies the authority because every indexed report is an extension contribution.
     * Isolated domain and presenter tests may omit it; an empty registry never needs it.
     *
     * @param   list<ReportDefinition>   $reports    Active reconciled contributions.
     * @param   ?ExtensionExecutionGate  $execution  Live authority for the generation that declared them.
     *
     * @throws  InvalidArgumentException  When a member is invalid or an identifier is duplicated.
     *
     * @since   2.0.0
     */
    public function __construct(
        array $reports,
        private ?ExtensionExecutionGate $execution = null,
    ) {
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
        $this->assertCurrent();

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
        $this->assertCurrent();

        return array_values($this->reports);
    }

    /**
     * Fence a non-empty contributed report snapshot against live runtime authority.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When this report snapshot belongs to a superseded generation.
     *
     * @since   2.0.0
     */
    private function assertCurrent(): void
    {
        if ($this->reports !== []) {
            $this->execution?->assertCurrent();
        }
    }
}
