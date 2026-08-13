<?php

declare(strict_types=1);

namespace Kumwe\CMS\Audit\Infrastructure\Storage;

use InvalidArgumentException;
use Kumwe\CMS\Audit\Application\AuditArchiveStorage;
use Kumwe\CMS\Audit\Domain\StoredAuditArchive;
use RuntimeException;

/**
 * Private, permission-locked filesystem store for audit archives with atomic publication.
 *
 * This mirrors the report-export artifact store's guarantees: a `0700` non-public directory, a `0600`
 * file written through a temporary name, fsynced, then published atomically with a hard link so a
 * half-written archive can never carry the final name, and a checksum computed over exactly the bytes
 * that streamed through. Archives are never overwritten — a random attempt token in the key makes
 * every store call land on a fresh object.
 *
 * @since  2.0.0
 */
final readonly class FilesystemAuditArchiveStorage implements AuditArchiveStorage
{
    /**
     * Prepare the non-public archive directory and byte ceiling.
     *
     * @param   string  $directory     Absolute non-public storage directory.
     * @param   int     $maximumBytes  Maximum bytes per archive, up to 512 MiB.
     *
     * @throws  InvalidArgumentException  When the byte ceiling is invalid.
     * @throws  RuntimeException  When the directory cannot be secured.
     *
     * @since   2.0.0
     */
    public function __construct(private string $directory, private int $maximumBytes = 134_217_728)
    {
        if ($maximumBytes < 1 || $maximumBytes > 536_870_912) {
            throw new InvalidArgumentException('The audit archive byte ceiling is invalid.');
        }
        if (!str_starts_with($directory, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The audit archive directory must be absolute.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('The audit archive directory cannot be created.');
        }
        if (is_link($directory) || chmod($directory, 0700) === false) {
            throw new RuntimeException('The audit archive directory is unsafe.');
        }
    }

    /**
     * Write, hash, fsync and atomically publish one attempt-tokenized, never-overwritten NDJSON file.
     *
     * @param   string            $archiveId  Canonical UUID naming this archive.
     * @param   iterable<string>  $chunks     Ordered NDJSON chunks.
     *
     * @return  StoredAuditArchive  Immutable stored-byte evidence.
     *
     * @throws  RuntimeException  When a chunk, byte bound or filesystem operation is invalid.
     *
     * @since   2.0.0
     */
    public function store(string $archiveId, iterable $chunks): StoredAuditArchive
    {
        $key = $archiveId . '.' . bin2hex(random_bytes(16)) . '.ndjson';
        $this->assertKey($key);
        $path = $this->directory . DIRECTORY_SEPARATOR . $key;
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('An audit archive object already exists.');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $stream = fopen($temporary, 'x+b');
        if ($stream === false) {
            throw new RuntimeException('A temporary audit archive cannot be created.');
        }
        $hash = hash_init('sha256');
        $size = 0;
        try {
            chmod($temporary, 0600);
            foreach ($chunks as $chunk) {
                $size += strlen($chunk);
                if ($size > $this->maximumBytes) {
                    throw new RuntimeException('An audit archive exceeds its byte ceiling.');
                }
                hash_update($hash, $chunk);
                $this->write($stream, $chunk);
            }
            if ($size < 1) {
                throw new RuntimeException('An audit archive cannot be empty.');
            }
            fflush($stream);
            if (function_exists('fsync')) {
                fsync($stream);
            }
        } catch (\Throwable $exception) {
            fclose($stream);
            @unlink($temporary);
            throw $exception;
        }
        fclose($stream);
        if (!link($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('The audit archive could not be published atomically.');
        }
        @unlink($temporary);
        chmod($path, 0600);

        return new StoredAuditArchive($key, $size, hash_final($hash));
    }

    /**
     * Validate a storage key before confined path resolution.
     *
     * @param   string  $key  Candidate storage key derived from the archive id and attempt token.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the key does not match the confined archive-name shape.
     *
     * @since   2.0.0
     */
    private function assertKey(string $key): void
    {
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}'
                . '\.[0-9a-f]{32}\.ndjson$/D',
                $key,
            ) !== 1
        ) {
            throw new RuntimeException('An audit archive storage key is invalid.');
        }
    }

    /**
     * Write bytes completely to an already opened archive stream.
     *
     * @param   resource  $stream  Opened archive stream that receives all bytes.
     * @param   string    $bytes   Complete chunk bytes to write.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the stream refuses to accept the remaining bytes.
     *
     * @since   2.0.0
     */
    private function write(mixed $stream, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($stream, $bytes);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Audit archive bytes could not be written.');
            }
            $bytes = substr($bytes, $written);
        }
    }
}
