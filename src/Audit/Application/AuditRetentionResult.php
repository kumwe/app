<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Application;

/**
 * Outcome of one retention pass: what was archived and pruned, or why nothing was.
 *
 * A pass that prunes always archived first, so a non-zero pruned count implies the archive evidence
 * fields are populated. A pass that prunes nothing reports zero counts and null evidence — either the
 * window is disabled, nothing anchored has aged out yet, or the eligible range still holds rows
 * younger than the cutoff.
 *
 * @since  2.0.0
 */
final readonly class AuditRetentionResult
{
    /**
     * Capture the retention pass outcome.
     *
     * @param  int      $prunedCount    Audit rows archived and removed by this pass; zero when none.
     * @param  ?int     $fromPosition   First pruned position, or null when nothing was pruned.
     * @param  ?int     $toPosition     Last pruned position, or null when nothing was pruned.
     * @param  ?string  $archiveKey     Storage key of the retention archive, or null when none.
     * @param  ?string  $archiveSha256  Checksum of the retention archive, or null when none.
     * @param  ?int     $pruneSequence  Anchor-ledger sequence of the prune mark, or null when none.
     *
     * @since  2.0.0
     */
    public function __construct(
        public int $prunedCount,
        public ?int $fromPosition = null,
        public ?int $toPosition = null,
        public ?string $archiveKey = null,
        public ?string $archiveSha256 = null,
        public ?int $pruneSequence = null,
    ) {
    }
}
