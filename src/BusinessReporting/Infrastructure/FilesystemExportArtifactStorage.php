<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Infrastructure;

use InvalidArgumentException;
use Kumwe\CMS\BusinessReporting\Application\ExportArtifactStorage;
use Kumwe\CMS\BusinessReporting\Application\StoredExportArtifact;
use RuntimeException;

/**
 * Private immutable filesystem store with atomic publication and verified reads.
 *
 * @since  2.0.0
 */
final readonly class FilesystemExportArtifactStorage implements ExportArtifactStorage
{
    /**
     * Prepare a non-public private artifact directory and byte ceiling.
     *
     * @param   string  $directory     Absolute non-public storage directory.
     * @param   int     $maximumBytes  Maximum bytes per artifact, up to 512 MiB.
     *
     * @throws  InvalidArgumentException  When the byte ceiling is invalid.
     * @throws  RuntimeException  When the directory cannot be secured.
     *
     * @since   2.0.0
     */
    public function __construct(private string $directory, private int $maximumBytes = 134_217_728)
    {
        if ($maximumBytes < 1 || $maximumBytes > 536_870_912) {
            throw new InvalidArgumentException('The export artifact byte ceiling is invalid.');
        }
        if (!str_starts_with($directory, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The export artifact directory must be absolute.');
        }
        // Suppressed diagnostics, typed refusals: an unwritable or read-only volume is reported once,
        // by the exception naming the step, rather than twice with a PHP warning saying the same thing.
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('The export artifact directory cannot be created.');
        }
        if (is_link($directory) || @chmod($directory, 0700) === false) {
            throw new RuntimeException('The export artifact directory is unsafe.');
        }
    }

    /**
     * Write, hash, fsync and atomically publish one attempt-tokenized, never-overwritten CSV file.
     *
     * @param   string            $artifactId  Canonical artifact UUID.
     * @param   iterable<string>  $chunks      Ordered CSV chunks.
     *
     * @return  StoredExportArtifact  Immutable stored-byte evidence.
     *
     * @throws  RuntimeException  When a chunk, byte bound or filesystem operation is invalid.
     *
     * @since   2.0.0
     */
    public function store(string $artifactId, iterable $chunks): StoredExportArtifact
    {
        $key = $artifactId . '.' . bin2hex(random_bytes(16)) . '.csv';
        $this->assertKey($key);
        $path = $this->path($key);
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('An export artifact object already exists.');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $stream = @fopen($temporary, 'x+b');
        if ($stream === false) {
            throw new RuntimeException('A temporary export artifact cannot be created.');
        }
        $hash = hash_init('sha256');
        $size = 0;
        try {
            chmod($temporary, 0600);
            foreach ($chunks as $chunk) {
                if (!is_string($chunk)) {
                    throw new RuntimeException('An export artifact chunk is not a string.');
                }
                $size += strlen($chunk);
                if ($size > $this->maximumBytes) {
                    throw new RuntimeException('An export artifact exceeds its byte ceiling.');
                }
                hash_update($hash, $chunk);
                $this->write($stream, $chunk);
            }
            if ($size < 1) {
                throw new RuntimeException('An export artifact cannot be empty.');
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
            throw new RuntimeException('The export artifact could not be published atomically.');
        }
        @unlink($temporary);
        chmod($path, 0600);

        return new StoredExportArtifact($key, $size, hash_final($hash));
    }

    /**
     * Verify path, size and checksum before returning a read-only stream.
     *
     * @param   StoredExportArtifact  $artifact  Expected private object evidence.
     *
     * @return  resource  Verified stream positioned at byte zero.
     *
     * @throws  RuntimeException  When object integrity fails.
     *
     * @since   2.0.0
     */
    public function open(StoredExportArtifact $artifact): mixed
    {
        $this->assertKey($artifact->key);
        $path = $this->path($artifact->key);
        if (is_link($path) || !is_file($path) || filesize($path) !== $artifact->size) {
            throw new RuntimeException('Export artifact storage integrity failed.');
        }
        $checksum = hash_file('sha256', $path);
        if (!is_string($checksum) || !hash_equals($artifact->checksum, $checksum)) {
            throw new RuntimeException('Export artifact checksum verification failed.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The export artifact cannot be opened.');
        }

        return $stream;
    }

    /**
     * Delete one expired private file without following a link.
     *
     * @param   string  $key  Opaque artifact key.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the key is unsafe or a present file cannot be removed.
     *
     * @since   2.0.0
     */
    public function delete(string $key): void
    {
        $this->assertKey($key);
        $path = $this->path($key);
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_file($path) || !unlink($path)) {
            throw new RuntimeException('An export artifact cannot be deleted safely.');
        }
    }

    /**
     * Resolve the confined filesystem path for the supplied identifier.
     *
     * @param   string  $key  Array or row key whose value is being read.
     *
     * @return  string  Confined absolute path for the requested export artifact.
     *
     * @since   2.0.0
     */
    private function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $key;
    }

    /**
     * Validate a storage key before confined path resolution.
     *
     * @param   string  $key  Array or row key whose value is being read.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertKey(string $key): void
    {
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}'
                . '\.[0-9a-f]{32}\.csv$/D',
                $key,
            ) !== 1
        ) {
            throw new RuntimeException('An export artifact storage key is invalid.');
        }
    }

    /**
     * Write bytes completely to an already opened artifact stream.
     *
     * @param   resource  $stream  Opened artifact stream that receives all bytes.
     * @param   string    $bytes   Complete artifact bytes to write.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function write(mixed $stream, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($stream, $bytes);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Export artifact bytes could not be written.');
            }
            $bytes = substr($bytes, $written);
        }
    }
}
