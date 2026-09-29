<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Infrastructure\Storage;

use DirectoryIterator;
use Kumwe\App\Audit\Infrastructure\Persistence\AuditLedgerEntry;
use Kumwe\Audit\Domain\AuditAnchorDigest;
use Kumwe\Audit\Domain\StoredAuditArchive;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Proves a just-written audit archive can be read back whole before any row it preserves is pruned.
 *
 * Writing an archive and trusting the write are different things. Before a retention pass deletes a
 * range it re-opens the archive from the private store, streams every byte back through SHA-256,
 * counts the NDJSON lines and decodes the manifest, and refuses unless the size and checksum match what
 * the store reported, the line count is the manifest line plus exactly the exported events, and the
 * manifest names the same position range. Only an archive that survives that re-read is one the anchor
 * ledger may cite; a torn, truncated or unreadable file fails the pass closed with the rows still in
 * place. Off-host copying remains the backup cycle's job and is documented as such.
 *
 * @since  2.0.0
 */
final readonly class FilesystemAuditArchiveVerifier
{
    /**
     * Bind the verifier to the archive directory.
     *
     * @param   string  $directory  Absolute private directory the archives are stored in.
     *
     * @throws  RuntimeException  When the directory is not absolute.
     *
     * @since   2.0.0
     */
    public function __construct(private string $directory)
    {
        if (!str_starts_with($directory, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The audit archive directory must be absolute.');
        }
    }

    /**
     * Preserve an immutable private receipt binding a prune claim to the archive actually read back.
     *
     * @param   string                  $id             Prune ledger UUID.
     * @param   string                  $digest         Complete chained digest of the prune ledger row.
     * @param   StoredAuditArchive      $archive        Protected archive preserving the removed events.
     * @param   int                     $fromPosition   First archived position.
     * @param   int                     $toPosition     Last archived position.
     * @param   int                     $eventCount     Number of archived events.
     * @param   string                  $rollingDigest  Ordered position and digest fold of the archived range.
     * @param   list<AuditLedgerEntry>  $anchors        Original immutable ranges the archive must reproduce.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the evidence cannot be verified or published immutably.
     *
     * @since   2.0.0
     */
    public function recordPrune(
        string $id,
        string $digest,
        StoredAuditArchive $archive,
        int $fromPosition,
        int $toPosition,
        int $eventCount,
        string $rollingDigest,
        array $anchors,
    ): void {
        $this->assertRestorable($archive, $fromPosition, $toPosition, $eventCount, $rollingDigest, $anchors);
        $path = $this->receiptPath($id);
        $directory = dirname($path);
        $storage = new FilesystemAuditArchiveStorage($directory);
        $receipt = $storage->store($id, [json_encode([
            'prune_digest' => $digest,
            'archive_key' => $archive->key,
            'archive_size' => $archive->size,
            'archive_sha256' => $archive->checksum,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n"]);
        $temporary = $directory . DIRECTORY_SEPARATOR . $receipt->key;
        try {
            if (!link($temporary, $path)) {
                throw new RuntimeException('The protected audit prune receipt cannot be published.');
            }
        } finally {
            unlink($temporary);
        }
    }

    /**
     * Reopen a prune receipt and its archive; database-only claims never substitute for these bytes.
     *
     * @param   AuditLedgerEntry        $entry    Immutable database prune claim to verify.
     * @param   list<AuditLedgerEntry>  $anchors  Original sealed ranges covered by this prune claim.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the receipt or archive is absent, altered, or names different evidence.
     *
     * @since   2.0.0
     */
    public function assertPruned(AuditLedgerEntry $entry, array $anchors): void
    {
        $path = $this->receiptPath($entry->id);
        if (is_link(dirname($path)) || is_link($path)) {
            throw new RuntimeException('The audit prune claim has no protected archive receipt.');
        }
        if (!is_file($path)) {
            $this->assertLegacyArchive($entry, $anchors);

            return;
        }
        $bytes = file_get_contents($path, false, null, 0, 4097);
        $receipt = is_string($bytes) && strlen($bytes) <= 4096 ? json_decode($bytes, true) : null;
        if (
            !is_array($receipt)
            || ($receipt['prune_digest'] ?? null) !== $entry->digest
            || ($receipt['archive_sha256'] ?? null) !== $entry->archiveSha256
            || !is_string($receipt['archive_key'] ?? null)
            || !is_int($receipt['archive_size'] ?? null)
            || $receipt['archive_size'] < 1
            || $entry->archiveSha256 === null
        ) {
            throw new RuntimeException('The protected audit prune receipt does not match the ledger.');
        }
        $this->assertRestorable(
            new StoredAuditArchive($receipt['archive_key'], $receipt['archive_size'], $entry->archiveSha256),
            $entry->fromPosition,
            $entry->toPosition,
            $entry->rowCount,
            $entry->rollingDigest,
            $anchors,
        );
    }

    /**
     * Verify pre-receipt retention using the actual private archive and original immutable anchor ranges.
     *
     * The compatibility search reads at most 1024 directory entries and 512 MiB of matching archive
     * candidates. It never creates receipts or accepts database-only evidence. Sites above these bounds
     * must recover the exact protected receipt or reconcile their archive inventory operationally.
     *
     * @param   AuditLedgerEntry        $entry    Historical prune claim.
     * @param   list<AuditLedgerEntry>  $anchors  Sealed ranges the archived events must still reproduce.
     *
     * @return  void
     *
     * @throws  RuntimeException  When no bounded, authentic archive proves the retained evidence.
     *
     * @since   2.0.0
     */
    private function assertLegacyArchive(AuditLedgerEntry $entry, array $anchors): void
    {
        if ($entry->archiveSha256 === null || !is_dir($this->directory) || is_link($this->directory)) {
            throw new RuntimeException('The audit prune claim has no protected archive evidence.');
        }
        $candidates = 0;
        $bytes = 0;
        foreach (new DirectoryIterator($this->directory) as $file) {
            if ($file->isDot()) {
                continue;
            }
            if (++$candidates > 1024) {
                break;
            }
            if ($file->isLink() || !$file->isFile() || !str_ends_with($file->getFilename(), '.ndjson')) {
                continue;
            }
            $header = file_get_contents($file->getPathname(), false, null, 0, 4096);
            if (!is_string($header) || ($newline = strpos($header, "\n")) === false) {
                continue;
            }
            $manifest = json_decode(substr($header, 0, $newline), true);
            if (
                !is_array($manifest)
                || ($manifest['from_position'] ?? null) !== $entry->fromPosition
                || ($manifest['to_position'] ?? null) !== $entry->toPosition
            ) {
                continue;
            }
            $size = $file->getSize();
            // Checksum and ordered-digest verification each stream the archive once.
            $bytes += 2 * $size;
            if ($size < 1 || $bytes > 536_870_912) {
                break;
            }
            try {
                $this->assertRestorable(
                    new StoredAuditArchive($file->getFilename(), $size, $entry->archiveSha256),
                    $entry->fromPosition,
                    $entry->toPosition,
                    $entry->rowCount,
                    $entry->rollingDigest,
                    $anchors,
                );

                return;
            } catch (RuntimeException) {
                // Only exact private bytes count; an unrelated export may share the same range.
            }
        }

        throw new RuntimeException('No protected audit archive proves this prune claim within verification bounds.');
    }

    /**
     * Resolve a prune UUID to its confined private receipt path.
     *
     * @param   string  $id  Canonical prune UUID.
     *
     * @return  string  Absolute path outside database authority and public storage.
     *
     * @throws  RuntimeException  When the identifier cannot safely name a receipt.
     *
     * @since   2.0.0
     */
    private function receiptPath(string $id): string
    {
        if (!Uuid::isValid($id)) {
            throw new RuntimeException('The audit prune receipt identifier is invalid.');
        }

        return $this->directory . DIRECTORY_SEPARATOR . 'retention-proofs' . DIRECTORY_SEPARATOR . $id . '.json';
    }

    /**
     * Re-read an archive and refuse unless it matches what was written.
     *
     * @param   StoredAuditArchive      $archive        Archive as the store reported it.
     * @param   int                     $fromPosition   First position the archive must declare.
     * @param   int                     $toPosition     Last position the archive must declare.
     * @param   int                     $eventCount     Events the archive must hold.
     * @param   ?string                 $rollingDigest  Expected ordered event-digest fold for retention evidence.
     * @param   list<AuditLedgerEntry>  $anchors        Original immutable ranges the archive must reproduce.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the archive is missing, unreadable, differs in size or checksum, holds
     *          the wrong number of lines, or declares another range.
     *
     * @since   2.0.0
     */
    public function assertRestorable(
        StoredAuditArchive $archive,
        int $fromPosition,
        int $toPosition,
        int $eventCount,
        ?string $rollingDigest = null,
        array $anchors = [],
    ): void {
        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/D', $archive->key) !== 1
            || str_contains($archive->key, '..')
        ) {
            throw new RuntimeException('The audit archive key is not a safe file name.');
        }
        $path = $this->directory . DIRECTORY_SEPARATOR . $archive->key;
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('The audit archive is not present in the private store.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The audit archive cannot be opened for verification.');
        }
        $hash = hash_init('sha256');
        $size = 0;
        $lines = 0;
        $manifest = null;
        $tail = '';
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 65_536);
                if ($chunk === false) {
                    throw new RuntimeException('The audit archive could not be read back.');
                }
                if ($chunk === '') {
                    continue;
                }
                $size += strlen($chunk);
                hash_update($hash, $chunk);
                $lines += substr_count($chunk, "\n");
                if ($manifest === null) {
                    $tail .= $chunk;
                    $newline = strpos($tail, "\n");
                    if ($newline !== false) {
                        $manifest = substr($tail, 0, $newline);
                        $tail = '';
                    }
                }
            }
        } finally {
            fclose($stream);
        }
        if ($size !== $archive->size || !hash_equals($archive->checksum, hash_final($hash))) {
            throw new RuntimeException('The audit archive read back differs from what was written.');
        }
        if ($lines !== $eventCount + 1) {
            throw new RuntimeException('The audit archive does not hold exactly the exported events.');
        }
        $decoded = $manifest === null ? null : json_decode($manifest, true);
        if (
            !is_array($decoded)
            || ($decoded['kumwe_audit_archive'] ?? null) !== 1
            || ($decoded['from_position'] ?? null) !== $fromPosition
            || ($decoded['to_position'] ?? null) !== $toPosition
        ) {
            throw new RuntimeException('The audit archive manifest does not declare the pruned range.');
        }
        if ($rollingDigest !== null) {
            $this->assertRollingDigest($path, $fromPosition, $toPosition, $eventCount, $rollingDigest, $anchors);
        }
    }

    /**
     * Bind archived event positions and digests to the sealed range without trusting a line count alone.
     *
     * @param   string                  $path           Verified archive path.
     * @param   int                     $fromPosition   Inclusive position lower bound.
     * @param   int                     $toPosition     Inclusive position upper bound.
     * @param   int                     $eventCount     Expected number of event records.
     * @param   string                  $rollingDigest  Expected SHA-256 fold of ordered position and digest pairs.
     * @param   list<AuditLedgerEntry>  $anchors        Original immutable ranges to fold during the same archive walk.
     *
     * @return  void
     *
     * @throws  RuntimeException  When archive records do not reproduce the retained evidence.
     *
     * @since   2.0.0
     */
    private function assertRollingDigest(
        string $path,
        int $fromPosition,
        int $toPosition,
        int $eventCount,
        string $rollingDigest,
        array $anchors,
    ): void {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The audit archive cannot be reopened for evidence verification.');
        }
        $hash = hash_init('sha256');
        hash_update($hash, AuditAnchorDigest::CHAIN_CONTEXT . "\n");
        $cursor = $fromPosition - 1;
        $count = 0;
        $anchorHashes = [];
        $anchorCounts = [];
        foreach ($anchors as $index => $anchor) {
            $anchorHashes[$index] = hash_init('sha256');
            hash_update($anchorHashes[$index], AuditAnchorDigest::CHAIN_CONTEXT . "\n");
            $anchorCounts[$index] = 0;
        }
        $anchorIndex = 0;
        try {
            fgets($stream);
            while (($line = fgets($stream, 1_048_577)) !== false) {
                $event = json_decode($line, true);
                if (
                    !str_ends_with($line, "\n")
                    || !is_array($event)
                    || !is_int($event['position'] ?? null)
                    || $event['position'] <= $cursor
                    || $event['position'] > $toPosition
                    || !is_string($event['digest'] ?? null)
                    || preg_match('/^[0-9a-f]{64}$/D', $event['digest']) !== 1
                ) {
                    throw new RuntimeException('The audit archive contains invalid ordered event evidence.');
                }
                $cursor = $event['position'];
                hash_update($hash, $cursor . ':' . $event['digest'] . "\n");
                $count++;
                if ($anchors !== []) {
                    while (isset($anchors[$anchorIndex]) && $cursor > $anchors[$anchorIndex]->toPosition) {
                        $anchorIndex++;
                    }
                    if (!isset($anchors[$anchorIndex]) || $cursor < $anchors[$anchorIndex]->fromPosition) {
                        throw new RuntimeException('The audit archive contains an event outside its original anchors.');
                    }
                    hash_update($anchorHashes[$anchorIndex], $cursor . ':' . $event['digest'] . "\n");
                    $anchorCounts[$anchorIndex]++;
                }
            }
            if (!feof($stream) || $count !== $eventCount || !hash_equals($rollingDigest, hash_final($hash))) {
                throw new RuntimeException('The audit archive does not reproduce the sealed event evidence.');
            }
            foreach ($anchors as $index => $anchor) {
                if (
                    $anchorCounts[$index] !== $anchor->rowCount
                    || !hash_equals($anchor->rollingDigest, hash_final($anchorHashes[$index]))
                ) {
                    throw new RuntimeException('The audit archive does not reproduce its original immutable anchors.');
                }
            }
        } finally {
            fclose($stream);
        }
    }
}
