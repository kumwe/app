<?php

declare(strict_types=1);

namespace Kumwe\App\Content\Application;

use InvalidArgumentException;

/**
 * Validated filter, sort and paging state for one administrator content-browser request.
 *
 * Every field arrives from the query string, and two of them — scope and sort — end up choosing SQL
 * predicates and `ORDER BY` clauses. Validating the whole set here, at construction, is what lets
 * `ContentSearchRepository` implementations map them without re-checking and without ever
 * interpolating operator input into the SQL grammar. The object is also the source of truth for
 * pagination links, since `toQueryParameters()` reproduces exactly the request that built it.
 *
 * @since  2.0.0
 */
final readonly class ContentBrowseQuery
{
    /**
     * Trash scopes a browse may ask for, each mapping to a `deleted_at` predicate.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const SCOPES = ['active', 'trashed', 'all'];

    /**
     * Sort keys a browse may ask for, each mapping to a fixed, tie-broken `ORDER BY` clause.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const SORTS = ['updated_desc', 'updated_asc', 'title_asc', 'title_desc'];

    /**
     * Validate one browse request, rejecting anything a repository could not safely act on.
     *
     * @param   string  $search       Free text matched against title and slug; blank disables the filter.
     * @param   string  $status       Workflow state key to restrict to; blank means every state.
     * @param   string  $contentType  UUID of the content type to restrict to; blank means every type.
     * @param   string  $scope        One of `active`, `trashed` or `all`, selecting the trash predicate.
     * @param   string  $sort         One of the `SORTS` keys, selecting the ordering.
     * @param   int     $page         One-based page number, capped so the offset cannot overflow.
     * @param   int     $perPage      Page size; only 10, 25 and 50 are offered by the browser.
     *
     * @throws  InvalidArgumentException  When any value is out of range, malformed, or off the vocabulary.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $search = '',
        public string $status = '',
        public string $contentType = '',
        public string $scope = 'active',
        public string $sort = 'updated_desc',
        public int $page = 1,
        public int $perPage = 25,
    ) {
        if (mb_strlen($search) > 160) {
            throw new InvalidArgumentException('The content search may not exceed 160 characters.');
        }
        if ($status !== '' && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $status) !== 1) {
            throw new InvalidArgumentException('The content status filter is invalid.');
        }
        if (
            $contentType !== ''
            && preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',
                $contentType,
            ) !== 1
        ) {
            throw new InvalidArgumentException('The content type filter is invalid.');
        }
        if (!in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('The content scope filter is invalid.');
        }
        if (!in_array($sort, self::SORTS, true)) {
            throw new InvalidArgumentException('The content sort is invalid.');
        }
        if ($page < 1 || $page > 100_000) {
            throw new InvalidArgumentException('The content page is invalid.');
        }
        if (!in_array($perPage, [10, 25, 50], true)) {
            throw new InvalidArgumentException('The content page size is invalid.');
        }
    }

    /**
     * Return the same query aimed at a different page, keeping every filter and the sort intact.
     *
     * This is how the previous and next links are built, so the page number is validated again on the
     * way through rather than trusted from arithmetic done by the caller.
     *
     * @param   int  $page  One-based page number to move to.
     *
     * @return  self  A new query; the receiver is left untouched.
     *
     * @throws  InvalidArgumentException  When the page number falls outside the accepted range.
     *
     * @since   2.0.0
     */
    public function withPage(int $page): self
    {
        return new self(
            $this->search,
            $this->status,
            $this->contentType,
            $this->scope,
            $this->sort,
            $page,
            $this->perPage,
        );
    }

    /**
     * Render the query back into the public query-string parameters that would reproduce it.
     *
     * Defaults are omitted so that the browser's own links stay short and a page-one, unfiltered
     * listing keeps a bare URL. Keys are the short public names — `q`, `type`, `per_page` — not the
     * property names.
     *
     * @return  array<string, int|string>  Only the values that differ from their defaults.
     *
     * @since   2.0.0
     */
    public function toQueryParameters(): array
    {
        $parameters = [];
        if ($this->search !== '') {
            $parameters['q'] = $this->search;
        }
        if ($this->status !== '') {
            $parameters['status'] = $this->status;
        }
        if ($this->contentType !== '') {
            $parameters['type'] = $this->contentType;
        }
        if ($this->scope !== 'active') {
            $parameters['scope'] = $this->scope;
        }
        if ($this->sort !== 'updated_desc') {
            $parameters['sort'] = $this->sort;
        }
        if ($this->page !== 1) {
            $parameters['page'] = $this->page;
        }
        if ($this->perPage !== 25) {
            $parameters['per_page'] = $this->perPage;
        }

        return $parameters;
    }
}
