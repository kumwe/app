<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure\Package;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Application\Package\ArchiveEntry;
use Kumwe\CMS\Extension\Application\Package\ArchiveEntryType;
use Kumwe\CMS\Extension\Application\Package\ArchivePackage;
use Kumwe\CMS\Extension\Application\Package\ArchiveReader;
use Kumwe\CMS\Extension\Domain\PackagePath;
use RuntimeException;
use ZipArchive;

final class ZipArchiveReader implements ArchiveReader
{
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
