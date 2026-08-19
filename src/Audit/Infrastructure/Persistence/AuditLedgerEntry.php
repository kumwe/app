<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Infrastructure\Persistence;

/**
 * One validated row of the `audit_anchors` ledger as the writers and the verifier consume it.
 *
 * Rows are hydrated through `AuditLedger`, which validates every field's shape before this object
 * exists, so consumers can chain, recompute and compare digests without re-checking storage types.
 *
 * @since  2.0.0
 */
final readonly class AuditLedgerEntry
{
    /**
     * Capture one validated ledger row.
     *
     * @param  string   $id              Canonical UUID of the ledger row.
     * @param  int      $sequence        Gapless ledger sequence number.
     * @param  string   $kind            Entry kind: `anchor` for a seal, `prune` for a retention mark.
     * @param  int      $fromPosition    First audit position the range covers, inclusive.
     * @param  int      $toPosition      Last audit position the range covers, inclusive.
     * @param  int      $rowCount        Audit rows the range held when the entry was written.
     * @param  string   $rollingDigest   Rolling digest of the covered range.
     * @param  ?string  $previousDigest  Digest of the preceding ledger row, or null for the first.
     * @param  string   $digest          The entry's own chained digest.
     * @param  ?string  $archiveSha256   Checksum of a prune mark's archive, or null.
     * @param  string   $createdAt       Creation instant formatted as `Y-m-d H:i:s`.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $id,
        public int $sequence,
        public string $kind,
        public int $fromPosition,
        public int $toPosition,
        public int $rowCount,
        public string $rollingDigest,
        public ?string $previousDigest,
        public string $digest,
        public ?string $archiveSha256,
        public string $createdAt,
    ) {
    }
}
