<?php

declare(strict_types=1);

namespace Kumwe\CMS\Audit\Domain;

use InvalidArgumentException;
use Kumwe\CMS\Application\Automation\CanonicalJson;

/**
 * Digest arithmetic for the chained `audit_anchors` ledger that seals ranges of the audit trail.
 *
 * An anchor freezes one contiguous position range of `audit_events`: the rolling digest binds every
 * event digest to its position and order inside the range, the row count fixes how many rows the range
 * held, and the anchor's own digest chains to the previous anchor so the ledger itself cannot be
 * truncated or rewritten without breaking a link. The rolling digest is computed incrementally over
 * `position:digest` lines, which is what makes a swap of two rows' positions — an order change that
 * leaves every per-event digest intact — change the anchored value. Prune marks reuse the identical
 * arithmetic so the evidence for a retention window that was archived and removed stays inside the
 * same chain the verifier walks.
 *
 * @since  2.0.0
 */
final class AuditAnchorDigest
{
    /**
     * Versioned context string prefixed to both the rolling and the anchor digests.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string CHAIN_CONTEXT = 'kumwe-audit-anchor-v1';

    /**
     * Fold one ordered sequence of `position => event digest` pairs into the range's rolling digest.
     *
     * @param   iterable<int, string>  $digestsByPosition  Event digests keyed by position, in ascending
     *          position order exactly as the range stores them.
     *
     * @return  string  Lowercase hexadecimal SHA-256 binding both the digests and their order.
     *
     * @since   2.0.0
     */
    public static function rolling(iterable $digestsByPosition): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, self::CHAIN_CONTEXT . "\n");
        foreach ($digestsByPosition as $position => $digest) {
            hash_update($hash, $position . ':' . $digest . "\n");
        }

        return hash_final($hash);
    }

    /**
     * Compute the self-digest one anchor row stores, chaining it to its predecessor.
     *
     * @param   int      $sequence        Gapless ledger sequence number of this anchor row.
     * @param   string   $kind            Anchor kind: `anchor` for a seal, `prune` for a retention mark.
     * @param   int      $fromPosition    First audit position the range covers, inclusive.
     * @param   int      $toPosition      Last audit position the range covers, inclusive.
     * @param   int      $rowCount        Number of audit rows the range held when it was sealed.
     * @param   string   $rollingDigest   Rolling digest of the covered range.
     * @param   ?string  $previousDigest  Digest of the preceding anchor row, or null for the first.
     * @param   ?string  $archiveSha256   Checksum of the archive a prune mark preserved, or null.
     * @param   string   $createdAt       Creation instant formatted as `Y-m-d H:i:s`.
     *
     * @return  string  Lowercase hexadecimal SHA-256 of the canonical anchor document.
     *
     * @throws  InvalidArgumentException  When a field cannot be represented as canonical JSON.
     *
     * @since   2.0.0
     */
    public static function compute(
        int $sequence,
        string $kind,
        int $fromPosition,
        int $toPosition,
        int $rowCount,
        string $rollingDigest,
        ?string $previousDigest,
        ?string $archiveSha256,
        string $createdAt,
    ): string {
        return hash('sha256', self::CHAIN_CONTEXT . "\n" . CanonicalJson::encode([
            'sequence' => $sequence,
            'kind' => $kind,
            'from_position' => $fromPosition,
            'to_position' => $toPosition,
            'row_count' => $rowCount,
            'rolling_digest' => $rollingDigest,
            'previous_digest' => $previousDigest,
            'archive_sha256' => $archiveSha256,
            'created_at' => $createdAt,
        ]));
    }
}
