<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure\Package;

use Generator;
use InvalidArgumentException;
use Kumwe\CMS\Extension\Application\Package\ArchiveContentReader;
use RuntimeException;
use ZipArchive;

/**
 * Streams an extension archive's entries through ext/zip, one expanded entry at a time.
 *
 * This is the shipped `ArchiveContentReader` binding and the only place install-time admission expands
 * packaged bytes. Each entry is read with an explicit length ceiling, so a header that under-reports an
 * entry's size cannot make the process expand more than the safety policy already budgeted for; a read
 * that comes back short or unreadable aborts rather than yielding a truncated file for inspection. The
 * archive handle is closed on every path, including an abandoned iteration.
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
                if (!is_array($stat) || !is_string($stat['name'] ?? null)) {
                    throw new RuntimeException('The ZIP archive contains an unreadable entry.');
                }
                $name = $stat['name'];
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $contents = $zip->getFromIndex($index, self::MAXIMUM_ENTRY_BYTES + 1, ZipArchive::FL_UNCHANGED);
                if (!is_string($contents) || strlen($contents) > self::MAXIMUM_ENTRY_BYTES) {
                    throw new RuntimeException(sprintf('Package entry %s could not be read.', $name));
                }

                yield $name => $contents;
            }
        } finally {
            $zip->close();
        }
    }
}
