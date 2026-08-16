<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application\Dashboard;

use InvalidArgumentException;
use Kumwe\CMS\Application\Presentation\Dashboard\DashboardPreferenceQuery;

/**
 * One bounded workflow-candidate page derived from the live filtered navigation catalogue.
 *
 * @since  2.0.0
 */
final readonly class DashboardWorkflowPage
{
    /**
     * Validate one deterministic page and its forward/backward evidence.
     *
     * @param   DashboardPreferenceQuery  $query        Independent validated dashboard browser state.
     * @param   list<DashboardWidget>     $candidates   Live workflow candidates on this page.
     * @param   bool                      $hasNext      Whether another representable page has a match.
     * @param   bool                      $browseLimit  Whether matches continue beyond the numeric page bound.
     *
     * @throws  InvalidArgumentException  When the bounded page or its cursor evidence is inconsistent.
     *
     * @since   2.0.0
     */
    public function __construct(
        public DashboardPreferenceQuery $query,
        public array $candidates,
        public bool $hasNext,
        public bool $browseLimit,
    ) {
        if (!array_is_list($candidates) || count($candidates) > DashboardWorkflowCatalog::PAGE_SIZE) {
            throw new InvalidArgumentException('A dashboard workflow page is malformed or unbounded.');
        }
        $seen = [];
        foreach ($candidates as $candidate) {
            if (
                !$candidate instanceof DashboardWidget
                || !$candidate->isWorkflow()
                || isset($seen[$candidate->id])
            ) {
                throw new InvalidArgumentException('A dashboard workflow page contains an invalid candidate.');
            }
            $seen[$candidate->id] = true;
        }
        if ($browseLimit && ($query->workflowPage !== DashboardPreferenceQuery::MAXIMUM_PAGE || $hasNext)) {
            throw new InvalidArgumentException('A dashboard workflow browse limit is inconsistent.');
        }
    }

    /**
     * Whether a prior workflow page is directly reachable.
     *
     * @return  bool  True after page one.
     *
     * @since   2.0.0
     */
    public function hasPrevious(): bool
    {
        return $this->query->workflowPage > 1;
    }
}
