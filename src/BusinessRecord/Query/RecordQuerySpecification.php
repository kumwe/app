<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Query;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;

/**
 * Complete, self-bounding description of one page of a business-record browse.
 *
 * Everything a caller may ask for — which records match, in what order, how many, from where, and how
 * much of each — arrives as this one object, and every bound it has to respect is enforced in its
 * constructor rather than at the database. A specification that exists is therefore already inside the
 * limits `DoctrineBusinessRecordQueryCompiler` is prepared to compile, which leaves the compiler free
 * to concentrate on resolving handles against the pinned definition and installed schema. Pagination
 * is keyset-only: `digest()` fingerprints every choice except the cursor, so that fingerprint is the
 * same for every page of one query and a page token cannot be replayed against a different query.
 *
 * @since  2.0.0
 */
final readonly class RecordQuerySpecification
{
    /**
     * Ordering keys applied in turn; empty leaves the compiler's newest-updated-first default in place.
     *
     * @var    list<RecordSort>
     * @since  2.0.0
     */
    public array $sorts;

    /**
     * What each returned record carries, standing in for an empty projection when the caller passed none.
     *
     * @var    RecordProjection
     * @since  2.0.0
     */
    public RecordProjection $projection;

    /**
     * Assemble one page request and reject it when any of its bounds is exceeded.
     *
     * The filter tree is measured rather than inspected: its own operation count, nesting depth and
     * relation-hop depth decide admission here, so an oversized query is refused before a single handle
     * is resolved or a statement is built.
     *
     * @param   ?RecordFilter      $filter           Predicate every returned record must satisfy; null
     *          matches every record in scope.
     * @param   ?RecordSearch      $search           Free-text search required in addition to the
     *          filter; null searches nothing.
     * @param   list<RecordSort>   $sorts            Ordering keys in priority order; empty orders by
     *          last update, newest first.
     * @param   ?RecordCursor      $after            Signed keyset cursor from the previous page; null
     *          starts at the first page.
     * @param   int                $pageSize         Most records one page returns, from 1 to 200.
     * @param   ?RecordProjection  $projection       What each record carries back; null takes every
     *          readable field with no includes or aggregates.
     * @param   bool               $includeArchived  True to also match archived records.
     * @param   bool               $includeDeleted   True to also match soft-deleted records.
     *
     * @throws  InvalidArgumentException  When the page size falls outside 1 to 200, more than five
     *          sorts are given, two sorts name the same field, or the filter nests deeper than 8,
     *          crosses more than 2 relationships, or holds more than 64 operations.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ?RecordFilter $filter = null,
        public ?RecordSearch $search = null,
        array $sorts = [],
        public ?RecordCursor $after = null,
        public int $pageSize = 50,
        ?RecordProjection $projection = null,
        public bool $includeArchived = false,
        public bool $includeDeleted = false,
    ) {
        if ($pageSize < 1 || $pageSize > 200 || count($sorts) > 5) {
            throw new InvalidArgumentException('A business-record query page or sort count exceeds its bound.');
        }
        if (
            $filter !== null
            && ($filter->depth() > 8 || $filter->relationDepth() > 2 || $filter->operationCount() > 64)
        ) {
            throw new InvalidArgumentException('A business-record query exceeds its depth or operation bound.');
        }
        $seenSorts = [];
        foreach ($sorts as $sort) {
            if (isset($seenSorts[$sort->field])) {
                throw new InvalidArgumentException('A business-record sort field is duplicated.');
            }
            $seenSorts[$sort->field] = true;
        }
        $this->sorts = array_values($sorts);
        $this->projection = $projection ?? new RecordProjection();
    }

    /**
     * Reduce every choice the specification makes to a canonical array.
     *
     * @param   bool  $includeCursor  False omits the cursor, which is what keeps the encoded form
     *          identical from one page of a query to the next.
     *
     * @return  array<string, mixed>  One snake-cased key per query choice; `filter` and `search` are
     *          null when unset, and `after` when unset or excluded.
     *
     * @since   2.0.0
     */
    public function toArray(bool $includeCursor = true): array
    {
        return [
            'filter' => $this->filter?->toArray(),
            'search' => $this->search?->toArray(),
            'sorts' => array_map(static fn (RecordSort $sort): array => $sort->toArray(), $this->sorts),
            'after' => $includeCursor ? $this->after?->value() : null,
            'page_size' => $this->pageSize,
            'projection' => $this->projection->toArray(),
            'include_archived' => $this->includeArchived,
            'include_deleted' => $this->includeDeleted,
        ];
    }

    /**
     * Fingerprint every choice this query makes except which page of it is being read.
     *
     * The cursor is left out deliberately: the value identifies the query, not a position inside it, so
     * it holds still across that query's pages. `DoctrineBusinessRecordQueryCompiler` hashes the same
     * canonical form into the digest it stamps on a cursor, together with the definition version and
     * scope the page was read under, and refuses a cursor whose digest does not match.
     *
     * @return  string  Lowercase 64-character SHA-256 over the canonical form of the specification.
     *
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When a value the query
     *          carries cannot be canonically encoded, a string that is not valid UTF-8 being the case
     *          the query's own bounds still admit.
     *
     * @since   2.0.0
     */
    public function digest(): string
    {
        return CanonicalDefinitionJson::checksum($this->toArray(false));
    }
}
