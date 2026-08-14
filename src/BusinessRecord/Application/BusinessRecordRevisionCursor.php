<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordRevision;

/**
 * Exclusive upper bound on a page of revision history, spelled in the log's own ordering key.
 *
 * A revision log is read newest first by record version, then revision number, then the internal record
 * key, and that third component is what makes the ordering total: a history window found by identity
 * digest can span more than one generation of the same public identity, and two generations number their
 * versions independently, so the first two components alone are not unique. A cursor that carried only a
 * record version therefore cut a page in the middle of rows it could not tell apart, and the next page
 * either repeated them or stepped over them. Carrying all three components makes the bound land exactly
 * where the previous page ended.
 *
 * `after()` is the bound a caller should prefer, because it is taken from a row it actually received.
 * `atVersion()` exists for the caller that only knows a record version — the public history surfaces
 * expose one — and is exact wherever the window resolves to a single generation, which the history use
 * case establishes before it reads a page.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordRevisionCursor
{
    /**
     * Hold one already-validated bound.
     *
     * @param  int      $recordVersion   Record version the next page must fall below, or tie against.
     * @param  ?int     $revisionNumber  Revision number within that version, or null for a version-only bound.
     * @param  ?string  $recordKey       Internal record key that resolves a tie on both numbers, or null.
     *
     * @since  2.0.0
     */
    private function __construct(
        public int $recordVersion,
        public ?int $revisionNumber,
        public ?string $recordKey,
    ) {
    }

    /**
     * Bound the next page by record version alone.
     *
     * @param   int  $recordVersion  Return only revisions strictly below this record version.
     *
     * @return  self  A bound that stops at the first row of the named version.
     *
     * @throws  InvalidArgumentException  When the record version is not positive.
     *
     * @since   2.0.0
     */
    public static function atVersion(int $recordVersion): self
    {
        if ($recordVersion < 1) {
            throw new InvalidArgumentException('A revision cursor record version must be positive.');
        }

        return new self($recordVersion, null, null);
    }

    /**
     * Bound the next page immediately after one revision the caller already holds.
     *
     * @param   BusinessRecordRevision  $revision  Oldest entry on the page just returned.
     *
     * @return  self  A total bound, so the next page neither repeats nor skips this row's neighbours.
     *
     * @since   2.0.0
     */
    public static function after(BusinessRecordRevision $revision): self
    {
        return new self($revision->recordVersion, $revision->revisionNumber, $revision->recordKey);
    }

    /**
     * Report whether this bound resolves every component of the ordering key.
     *
     * @return  bool  True when the revision number and record key are both present.
     *
     * @since   2.0.0
     */
    public function isTotal(): bool
    {
        return $this->revisionNumber !== null && $this->recordKey !== null;
    }
}
