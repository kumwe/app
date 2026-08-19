<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use InvalidArgumentException;

/**
 * One bounded page of a business record's revision log, as `BusinessRecordService::history()` returns it.
 *
 * The service asks the revision repository for one row more than the caller wanted; that extra row never
 * reaches this object, it becomes `$hasMore`, which is how a caller learns older revisions exist without
 * the service counting them. Paging continues by re-issuing the history query with `beforeVersion` taken
 * from the oldest revision on this page.
 *
 * @since  2.0.0
 */
final readonly class RecordHistoryResult
{
    /**
     * Revision views on this page, newest first by record version and then by revision number.
     *
     * Each view was rendered against the definition version its revision was written under, so a page
     * that spans a definition upgrade holds views of differing shapes. Re-indexed on construction, so
     * the list is contiguous from zero.
     *
     * @var    list<BusinessRecordRevisionView>
     * @since  2.0.0
     */
    public array $revisions;

    /**
     * Assemble one history page and hold it to its declared bound.
     *
     * @param   list<BusinessRecordRevisionView>  $revisions  Revision views for this page, newest first.
     * @param   bool                              $hasMore    Whether revisions older than the last one here
     *          remain to be paged through.
     *
     * @throws  InvalidArgumentException  When more than 200 revisions are handed in.
     *
     * @since   2.0.0
     */
    public function __construct(array $revisions, public bool $hasMore)
    {
        if (count($revisions) > 200) {
            throw new InvalidArgumentException('A business-record history result exceeds 200 revisions.');
        }
        $this->revisions = array_values($revisions);
    }
}
