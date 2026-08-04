<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Package;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\PackagePath;

final readonly class ArchiveEntry
{
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

    public function path(): PackagePath
    {
        return $this->path;
    }

    public function type(): ArchiveEntryType
    {
        return $this->type;
    }

    public function compressedBytes(): int
    {
        return $this->compressedBytes;
    }

    public function uncompressedBytes(): int
    {
        return $this->uncompressedBytes;
    }
}
