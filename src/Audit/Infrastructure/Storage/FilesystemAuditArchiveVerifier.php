<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Infrastructure\Storage;

use Kumwe\Audit\Domain\StoredAuditArchive;
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
     * Re-read an archive and refuse unless it matches what was written.
     *
     * @param   StoredAuditArchive  $archive       Archive as the store reported it.
     * @param   int                 $fromPosition  First position the archive must declare.
     * @param   int                 $toPosition    Last position the archive must declare.
     * @param   int                 $eventCount    Events the archive must hold.
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
    }
}
