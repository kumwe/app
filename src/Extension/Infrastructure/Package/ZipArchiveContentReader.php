<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Infrastructure\Package;

use Generator;
use InvalidArgumentException;
use Kumwe\App\Extension\Application\Package\ArchiveContentReader;
use RuntimeException;
use ZipArchive;

/**
 * Streams an extension archive's entries through ext/zip, one expanded entry at a time.
 *
 * This is the shipped `ArchiveContentReader` binding and the only place install-time admission expands
 * packaged bytes. Every entry is held to the per-entry ceiling the safety policy budgets for, and the
 * ceiling is enforced against the bytes that actually come out of the decompressor rather than against
 * the size the central directory claims, so a header that under-reports an entry cannot make the
 * process expand more than was budgeted. An entry whose expanded length disagrees with its declared
 * size is refused rather than yielded, because a truncated or over-long read describes an archive that
 * no longer matches its own index. The archive handle and every entry stream are closed on all paths,
 * including an abandoned iteration.
 *
 * Entries are pulled through a stream in fixed chunks rather than requested as one ceiling-sized read.
 * `ZipArchive::getFromIndex()` allocates the full length it is asked for and never releases the slack
 * once the entry turns out to be smaller, so asking for the ceiling cost the ceiling for every entry —
 * and for as long as the caller retained one. Admission retains two entries by design, the bill of
 * materials and the provenance statement, which pinned three ceilings' worth of buffers at once and
 * exhausted the deployed memory limit part-way through a perfectly ordinary package. Chunked reads
 * make the cost track the bytes an entry really has while leaving the ceiling exactly where it was.
 *
 * @since  2.0.0
 */
final class ZipArchiveContentReader implements ArchiveContentReader
{
    /**
     * Largest single entry expanded, matching the per-entry ceiling the safety policy enforces.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAXIMUM_ENTRY_BYTES = 67_108_864;

    /**
     * Bytes pulled from an entry stream per read, bounding the slack a partially read entry can hold.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int READ_CHUNK_BYTES = 262_144;

    /**
     * Expand each regular file entry in central-directory order.
     *
     * @param   string  $archiveFile  Path of the staged archive to read.
     *
     * @return  Generator<string, string>  Complete entry bytes keyed by package path.
     *
     * @throws  InvalidArgumentException  When the path is not a readable regular file, or ext/zip cannot
     *          open it.
     * @throws  RuntimeException  When an entry cannot be listed or read completely within its ceiling.
     *
     * @since   2.0.0
     */
    public function contents(string $archiveFile): Generator
    {
        if (!is_file($archiveFile) || is_link($archiveFile) || !is_readable($archiveFile)) {
            throw new InvalidArgumentException('The extension archive must be a readable regular file.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archiveFile, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException('The extension archive is not a readable ZIP file.');
        }

        try {
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (!is_array($stat) || !is_string($stat['name'] ?? null) || !is_int($stat['size'] ?? null)) {
                    throw new RuntimeException('The ZIP archive contains an unreadable entry.');
                }
                $name = $stat['name'];
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $declared = $stat['size'];
                if ($declared < 0 || $declared > self::MAXIMUM_ENTRY_BYTES) {
                    throw new RuntimeException(sprintf('Package entry %s could not be read.', $name));
                }
                $contents = $this->entry($zip, $index, $name);
                if (strlen($contents) !== $declared) {
                    throw new RuntimeException(sprintf('Package entry %s could not be read.', $name));
                }

                yield $name => $contents;
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Pull one entry out of the archive in chunks, stopping the moment it outgrows its ceiling.
     *
     * @param   ZipArchive  $zip    Open archive handle the entry belongs to.
     * @param   int         $index  Central-directory position of the entry being expanded.
     * @param   string      $name   Package path of the entry, used to describe a failure.
     *
     * @return  string  The entry's expanded bytes.
     *
     * @throws  RuntimeException  When the entry cannot be opened, cannot be read, or exceeds its ceiling.
     *
     * @since   2.0.0
     */
    private function entry(ZipArchive $zip, int $index, string $name): string
    {
        $stream = $zip->getStreamIndex($index, ZipArchive::FL_UNCHANGED);
        if (!is_resource($stream)) {
            throw new RuntimeException(sprintf('Package entry %s could not be read.', $name));
        }

        try {
            $contents = '';
            while (!feof($stream)) {
                $chunk = fread($stream, self::READ_CHUNK_BYTES);
                if (!is_string($chunk)) {
                    throw new RuntimeException(sprintf('Package entry %s could not be read.', $name));
                }
                if ($chunk === '') {
                    break;
                }
                $contents .= $chunk;
                if (strlen($contents) > self::MAXIMUM_ENTRY_BYTES) {
                    throw new RuntimeException(sprintf('Package entry %s could not be read.', $name));
                }
            }

            return $contents;
        } finally {
            fclose($stream);
        }
    }
}
