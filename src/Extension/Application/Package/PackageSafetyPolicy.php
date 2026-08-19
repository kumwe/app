<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Package;

/**
 * Gate an extension archive must pass between being read and being extracted.
 *
 * `DoctrineExtensionManager` calls this immediately after `ArchiveReader::inspect()` and before it
 * writes a single byte, which is what makes the limits meaningful: a decompression bomb, a symbolic
 * link, or a pair of paths that collide on a case-insensitive filesystem is refused while the archive
 * is still only a table of numbers. The limits arrive as constructor arguments so an installation can
 * tighten them without touching the rules themselves.
 *
 * @since  2.0.0
 */
final readonly class PackageSafetyPolicy
{
    /**
     * Configure the budget an archive has to stay inside.
     *
     * The defaults suit a normal extension: 4096 entries, 64 MiB per entry, 256 MiB expanded in total,
     * and an expansion ratio of 100 to 1.
     *
     * @param   int  $maximumEntries           Most entries an archive may declare.
     * @param   int  $maximumEntryBytes        Largest expanded size a single entry may reach.
     * @param   int  $maximumExpandedBytes     Largest expanded size the archive may reach in total.
     * @param   int  $maximumCompressionRatio  Highest expanded-to-compressed ratio one entry may show.
     *
     * @throws  \InvalidArgumentException  When any of the four limits is below one.
     *
     * @since   2.0.0
     */
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

    /**
     * Refuse an archive that cannot be unpacked safely, and say nothing when it can.
     *
     * Every rule is decided from the entry table alone, so nothing is unpacked to reach a verdict: the
     * entry count and the total expanded size stay inside their budgets, no entry is a symbolic link,
     * no two paths are equal once folded to lower case, no entry exceeds its own size limit or claims
     * to expand out of zero compressed bytes or past the ratio limit, and a `kumwe.json` file sits at
     * the archive root. Returning normally is the caller's sole permission to extract.
     *
     * @param   ArchivePackage  $package  Entry table read from the staged archive.
     *
     * @return  void
     *
     * @throws  UnsafePackage  When the archive breaks any of those rules.
     *
     * @since   2.0.0
     */
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
