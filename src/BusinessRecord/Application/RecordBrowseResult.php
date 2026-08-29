<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordCursor;

/**
 * One page of a business-record browse: the projected rows, where to continue, and any totals asked for.
 *
 * The read side answers a query one page at a time and reports nothing about the rest of the match by
 * default. This object is what a caller gets instead: the page itself, a signed cursor that is present
 * only while further rows remain, and the aggregates the specification requested, which are evaluated
 * over the whole match rather than over the page — a count aggregate is how a caller asks for a total.
 * Page and aggregate counts are bounded here too, so a defect further down cannot hand an unbounded
 * result up to the delivery layer.
 *
 * @since  2.0.0
 */
final readonly class RecordBrowseResult
{
    /**
     * Projected records on this page, in the order the query's sort produced them.
     *
     * Re-indexed on construction, so the list is contiguous from zero whatever keys were handed in.
     *
     * @var    list<BusinessRecordView>
     * @since  2.0.0
     */
    public array $records;

    /**
     * Aggregate results computed over every matching row, keyed by the alias the query named.
     *
     * Empty when the specification requested no aggregates. Values arrive as integers or as exact
     * decimal strings — the read side refuses a float rather than round one — and a null is a genuine
     * SQL null, as a minimum or average over an empty match is, not a missing alias.
     *
     * @var    array<string, int|string|null>
     * @since  2.0.0
     */
    public array $aggregates;

    /**
     * Assemble one page and hold it to the bounds the read side promises.
     *
     * @param   list<BusinessRecordView>        $records     Projected records for this page, in sort order.
     * @param   ?RecordCursor                   $nextCursor  Token to pass as the next query's `after`, or null
     *          when no further rows matched.
     * @param   array<string, int|string|null>  $aggregates  Aggregate results keyed by requested alias.
     *
     * @throws  InvalidArgumentException  When more than 200 records or more than 16 aggregates are handed in.
     *
     * @since   2.0.0
     */
    public function __construct(array $records, public ?RecordCursor $nextCursor = null, array $aggregates = [])
    {
        if (count($records) > 200 || count($aggregates) > 16) {
            throw new InvalidArgumentException('A business-record browse result exceeds its declared bounds.');
        }
        $this->records = array_values($records);
        $this->aggregates = $aggregates;
    }
}
