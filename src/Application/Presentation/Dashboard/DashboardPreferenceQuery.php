<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Presentation\Dashboard;

use InvalidArgumentException;

/**
 * Typed, protocol-neutral query for independent access-group and workflow-candidate pages.
 *
 * Delivery normalizes untrusted query-string values before constructing this value. The application
 * services own their fixed page sizes, while this value preserves both validated pages and searches.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceQuery
{
    /**
     * Largest normalized access-group search accepted by dashboard delivery.
     *
     * @var    int
     * @since  2.0.0
     */
    public const MAXIMUM_SEARCH_LENGTH = 64;

    /**
     * Largest workflow search, matching the canonical KIS surface-identifier ceiling.
     *
     * @var    int
     * @since  2.0.0
     */
    public const MAXIMUM_WORKFLOW_SEARCH_LENGTH = 191;

    /**
     * Largest directly addressable page; targeted search reaches catalogues beyond this numeric window.
     *
     * @var    int
     * @since  2.0.0
     */
    public const MAXIMUM_PAGE = 100;

    /**
     * Validate normalized access-group and workflow-candidate catalogue queries.
     *
     * @param   int     $page            One-based access-group page number.
     * @param   string  $search          Optional normalized role-code or role-name search.
     * @param   int     $workflowPage    One-based workflow-candidate page number.
     * @param   string  $workflowSearch  Optional normalized workflow identifier or label search.
     *
     * @throws  InvalidArgumentException  When page or normalized search is outside the contract.
     *
     * @since   2.0.0
     */
    public function __construct(
        public int $page = 1,
        public string $search = '',
        public int $workflowPage = 1,
        public string $workflowSearch = '',
    ) {
        if (
            $page < 1
            || $page > self::MAXIMUM_PAGE
            || $workflowPage < 1
            || $workflowPage > self::MAXIMUM_PAGE
        ) {
            throw new InvalidArgumentException('A dashboard preference page is outside the supported range.');
        }
        self::assertSearch($search, self::MAXIMUM_SEARCH_LENGTH);
        self::assertSearch($workflowSearch, self::MAXIMUM_WORKFLOW_SEARCH_LENGTH);
    }

    /**
     * Return the previous validated page while preserving search.
     *
     * @return  self  Previous page, or page one when already at the beginning.
     *
     * @since   2.0.0
     */
    public function previous(): self
    {
        return new self(
            max(1, $this->page - 1),
            $this->search,
            $this->workflowPage,
            $this->workflowSearch,
        );
    }

    /**
     * Return the next representable page while preserving search.
     *
     * @return  self  Next page.
     *
     * @throws  InvalidArgumentException  When the current page cannot be incremented safely.
     *
     * @since   2.0.0
     */
    public function next(): self
    {
        if ($this->page === self::MAXIMUM_PAGE) {
            throw new InvalidArgumentException('A dashboard preference page is outside the supported range.');
        }

        return new self(
            $this->page + 1,
            $this->search,
            $this->workflowPage,
            $this->workflowSearch,
        );
    }

    /**
     * Return the previous workflow page while preserving access-group browse state.
     *
     * @return  self  Previous workflow page, or page one when already at the beginning.
     *
     * @since   2.0.0
     */
    public function workflowPrevious(): self
    {
        return new self(
            $this->page,
            $this->search,
            max(1, $this->workflowPage - 1),
            $this->workflowSearch,
        );
    }

    /**
     * Return the next representable workflow page while preserving access-group browse state.
     *
     * @return  self  Next workflow page.
     *
     * @throws  InvalidArgumentException  When the current workflow page is already at the bound.
     *
     * @since   2.0.0
     */
    public function workflowNext(): self
    {
        if ($this->workflowPage === self::MAXIMUM_PAGE) {
            throw new InvalidArgumentException('A dashboard preference page is outside the supported range.');
        }

        return new self(
            $this->page,
            $this->search,
            $this->workflowPage + 1,
            $this->workflowSearch,
        );
    }

    /**
     * Clear only access-group browse state while preserving workflow discovery.
     *
     * @return  self  Query with neutral group state.
     *
     * @since   2.0.0
     */
    public function withoutAccessGroupBrowser(): self
    {
        return new self(1, '', $this->workflowPage, $this->workflowSearch);
    }

    /**
     * Clear only workflow browse state while preserving access-group discovery.
     *
     * @return  self  Query with neutral workflow state.
     *
     * @since   2.0.0
     */
    public function withoutWorkflowBrowser(): self
    {
        return new self($this->page, $this->search);
    }

    /**
     * Refuse non-normalized search values at the application boundary.
     *
     * @param   string  $search   Candidate normalized search.
     * @param   int     $maximum  Field-specific character ceiling.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the search is malformed or outside the bound.
     *
     * @since   2.0.0
     */
    private static function assertSearch(string $search, int $maximum): void
    {
        if (
            !mb_check_encoding($search, 'UTF-8')
            || mb_strlen($search, 'UTF-8') > $maximum
            || trim($search) !== $search
            || preg_match('/[\x00-\x1f\x7f]/u', $search) === 1
            || preg_match('/\s{2,}/u', $search) === 1
        ) {
            throw new InvalidArgumentException('A dashboard preference search must be normalized.');
        }
    }
}
