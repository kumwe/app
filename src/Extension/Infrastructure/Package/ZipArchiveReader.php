<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Infrastructure\Package;

use InvalidArgumentException;
use Kumwe\App\Extension\Application\Package\ArchiveEntry;
use Kumwe\App\Extension\Application\Package\ArchiveEntryType;
use Kumwe\App\Extension\Application\Package\ArchivePackage;
use Kumwe\App\Extension\Application\Package\ArchiveReader;
use Kumwe\App\Extension\Domain\PackagePath;
use RuntimeException;
use ZipArchive;

/**
 * Lists an extension archive through ext/zip's central directory, without expanding a single entry.
 *
 * This is the shipped `ArchiveReader` binding. It opens the staged upload read-only and reports what
 * the ZIP header claims — path, kind, stored size and declared expanded size — so `PackageSafetyPolicy`
 * can refuse a decompression bomb or a symbolic link while none of the package's bytes have reached the
 * filesystem. Link detection is the part ext/zip does not offer directly: the Unix mode is recovered
 * from the high half of the entry's external attributes and compared against `S_IFLNK`, which is what
 * stops a link from being read as an ordinary file. Every entry name is pushed through `PackagePath`,
 * so a traversal or control-character name fails here rather than at extraction time.
 *
 * @since  2.0.0
 */
final class ZipArchiveReader implements ArchiveReader
{
    /**
     * Read the archive's central directory into the description the safety policy judges.
     *
     * The path must name a readable regular file and never a symbolic link, so the bytes inspected are
     * the bytes later extracted. Sizes are the header's own claims and are reported as zero for
     * directory entries. The archive handle is closed on every path, including a rejected entry.
     *
     * @param   string  $archiveFile  Path of the staged archive file to inspect.
     *
     * @return  ArchivePackage  Every entry the central directory lists, in the order it lists them.
     *
     * @throws  InvalidArgumentException  When the path is not a readable regular file, when ext/zip
     *          cannot open it, when an entry name is not a safe relative package path, or when the
     *          archive holds no entries at all.
     * @throws  RuntimeException  When an entry's stat record or its external attributes cannot be read.
     *
     * @since   2.0.0
     */
    public function inspect(string $archiveFile): ArchivePackage
    {
        if (!is_file($archiveFile) || is_link($archiveFile) || !is_readable($archiveFile)) {
            throw new InvalidArgumentException('The extension archive must be a readable regular file.');
        }

        $zip = new ZipArchive();

        if ($zip->open($archiveFile, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException('The extension archive is not a readable ZIP file.');
        }

        try {
            $entries = [];

            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);

                if (!is_array($stat) || !is_string($stat['name'] ?? null)) {
                    throw new RuntimeException('The ZIP archive contains an unreadable entry.');
                }

                $name = $stat['name'];
                $type = str_ends_with($name, '/') ? ArchiveEntryType::Directory : ArchiveEntryType::File;
                $operatingSystem = 0;
                $attributes = 0;

                if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
                    if (!is_int($attributes)) {
                        throw new RuntimeException('The ZIP archive contains invalid external attributes.');
                    }

                    $unixType = ($attributes >> 16) & 0xF000;

                    if ($unixType === 0xA000) {
                        $type = ArchiveEntryType::SymbolicLink;
                    }
                }

                $entries[] = new ArchiveEntry(
                    PackagePath::fromString($name),
                    $type,
                    $type === ArchiveEntryType::Directory ? 0 : (int) ($stat['comp_size'] ?? 0),
                    $type === ArchiveEntryType::Directory ? 0 : (int) ($stat['size'] ?? 0),
                );
            }

            return new ArchivePackage($entries);
        } finally {
            $zip->close();
        }
    }
}
