<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Package;

final readonly class PackageSafetyPolicy
{
    public function __construct(
        private int $maximumEntries = 4_096,
        private int $maximumEntryBytes = 67_108_864,
        private int $maximumExpandedBytes = 268_435_456,
        private int $maximumCompressionRatio = 100,
    ) {
        if (min($maximumEntries, $maximumEntryBytes, $maximumExpandedBytes, $maximumCompressionRatio) < 1) {
            throw new \InvalidArgumentException('Package safety limits must be positive integers.');
        }
    }

    public function assertSafe(ArchivePackage $package): void
    {
        if (count($package->entries()) > $this->maximumEntries) {
            throw new UnsafePackage('The extension archive contains too many entries.');
        }

        $paths = [];
        $expandedBytes = 0;
        $hasManifest = false;

        foreach ($package->entries() as $entry) {
            if ($entry->type() === ArchiveEntryType::SymbolicLink) {
                throw new UnsafePackage('Symbolic links are forbidden in extension archives.');
            }

            $path = $entry->path()->value();
            $pathKey = strtolower($path);

            if (isset($paths[$pathKey])) {
                throw new UnsafePackage('Archive paths must be unique without relying on letter case.');
            }

            $paths[$pathKey] = true;
            $hasManifest = $hasManifest
                || ($path === 'kumwe.json' && $entry->type() === ArchiveEntryType::File);
            $expandedBytes += $entry->uncompressedBytes();

            if ($entry->uncompressedBytes() > $this->maximumEntryBytes) {
                throw new UnsafePackage('An extension archive entry exceeds the expanded size limit.');
            }

            if ($entry->uncompressedBytes() > 0 && $entry->compressedBytes() === 0) {
                throw new UnsafePackage('A non-empty archive entry reports an impossible compressed size.');
            }

            if (
                $entry->compressedBytes() > 0
                && $entry->uncompressedBytes() / $entry->compressedBytes() > $this->maximumCompressionRatio
            ) {
                throw new UnsafePackage('An extension archive entry exceeds the compression ratio limit.');
            }
        }

        if ($expandedBytes > $this->maximumExpandedBytes) {
            throw new UnsafePackage('The extension archive exceeds the total expanded size limit.');
        }

        if (!$hasManifest) {
            throw new UnsafePackage('The extension archive must contain kumwe.json at its root.');
        }
    }
}
