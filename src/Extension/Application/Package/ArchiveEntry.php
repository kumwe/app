<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Package;

use InvalidArgumentException;
use Kumwe\Extension\Package\PackagePath;

/**
 * One row of an extension archive's directory: what an entry is, where it sits, and how large it claims
 * to be.
 *
 * An `ArchiveReader` builds these from the archive's header alone, without expanding anything, so
 * `PackageSafetyPolicy` can reject a decompression bomb, a symbolic link or a case-colliding path while
 * none of the package's bytes have touched the filesystem. The sizes are therefore the archive's own
 * claims and are not evidence about the eventual output — treating them as budgets to enforce is the
 * point, and trusting them as facts is not.
 *
 * The constructor refuses a negative size and a directory that claims payload bytes, so no limit check
 * downstream has to weigh a negative budget or a directory that reads like a file.
 *
 * @since  2.0.0
 */
final readonly class ArchiveEntry
{
    /**
     * Record one entry as the archive directory describes it.
     *
     * @param   PackagePath       $path               Relative, traversal-free location the entry occupies
     *          inside the archive.
     * @param   ArchiveEntryType  $type               Whether the entry is a file, a directory, or a link.
     * @param   int               $compressedBytes    Bytes the entry occupies inside the archive; zero for a
     *          directory, and never negative.
     * @param   int               $uncompressedBytes  Bytes the header claims the entry expands to; zero for a
     *          directory, and never negative.
     *
     * @throws  InvalidArgumentException  When either size is negative, or a directory entry reports payload
     *          bytes.
     *
     * @since   2.0.0
     */
    public function __construct(
        private PackagePath $path,
        private ArchiveEntryType $type,
        private int $compressedBytes,
        private int $uncompressedBytes,
    ) {
        if ($compressedBytes < 0 || $uncompressedBytes < 0) {
            throw new InvalidArgumentException('Archive entry sizes cannot be negative.');
        }

        if ($type === ArchiveEntryType::Directory && ($compressedBytes !== 0 || $uncompressedBytes !== 0)) {
            throw new InvalidArgumentException('Archive directories cannot contain payload bytes.');
        }
    }

    /**
     * Return where the entry sits inside the archive.
     *
     * @return  PackagePath  Relative path, already proven free of absolute roots, `..` segments, backslashes
     *          and control characters; the safety policy compares these case-insensitively to catch
     *          collisions that only a case-insensitive filesystem would notice.
     *
     * @since   2.0.0
     */
    public function path(): PackagePath
    {
        return $this->path;
    }

    /**
     * Return what kind of entry this is.
     *
     * @return  ArchiveEntryType  The classification the reader derived from the archive directory; a
     *          symbolic link here is grounds for rejecting the whole package.
     *
     * @since   2.0.0
     */
    public function type(): ArchiveEntryType
    {
        return $this->type;
    }

    /**
     * Return how much room the entry takes up inside the archive.
     *
     * @return  int  Stored size in bytes, as the archive header reports it; zero for a directory. A file
     *          that claims expanded bytes yet zero stored bytes is treated as an impossible claim and
     *          rejects the package, since it would otherwise present an unbounded compression ratio.
     *
     * @since   2.0.0
     */
    public function compressedBytes(): int
    {
        return $this->compressedBytes;
    }

    /**
     * Return how large the entry claims it will be once expanded.
     *
     * @return  int  Expanded size in bytes as declared by the archive header, never verified against real
     *          output; zero for a directory. It is what the per-entry, whole-package and compression-ratio
     *          limits are measured against, which is why an inflated or absent claim is caught as a
     *          violation rather than believed.
     *
     * @since   2.0.0
     */
    public function uncompressedBytes(): int
    {
        return $this->uncompressedBytes;
    }
}
