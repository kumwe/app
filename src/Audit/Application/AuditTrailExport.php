<?php

declare(strict_types=1);

namespace Kumwe\CMS\Audit\Application;

use Kumwe\CMS\Audit\Domain\StoredAuditArchive;

/**
 * Manifest of one completed audit trail export: the archive, the range it covers and what it holds.
 *
 * The same values are recorded in the `audit.trail.exported` event's metadata, so the manifest an
 * operator holds and the trail's own record of the export can always be compared. The anchor sequence
 * names the newest anchor at export time, tying the archived range to the sealed evidence that
 * existed when it was taken.
 *
 * @since  2.0.0
 */
final readonly class AuditTrailExport
{
    /**
     * Capture the export manifest.
     *
     * @param  StoredAuditArchive  $archive         Stored file evidence: key, byte size and checksum.
     * @param  int                 $fromPosition    First audit position the export covers, inclusive.
     * @param  int                 $toPosition      Last audit position the export covers, inclusive.
     * @param  int                 $eventCount      Number of events written into the archive.
     * @param  int                 $redactedCount   Number of metadata values redacted on the way out.
     * @param  ?int                $anchorSequence  Newest anchor sequence at export time, or null when
     *         the ledger holds no anchor yet.
     *
     * @since  2.0.0
     */
    public function __construct(
        public StoredAuditArchive $archive,
        public int $fromPosition,
        public int $toPosition,
        public int $eventCount,
        public int $redactedCount,
        public ?int $anchorSequence,
    ) {
    }
}
