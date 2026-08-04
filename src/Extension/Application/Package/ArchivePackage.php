<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Package;

use InvalidArgumentException;

final readonly class ArchivePackage
{
    /** @var list<ArchiveEntry> */
    private array $entries;

    /** @param array<mixed> $entries */
    public function __construct(array $entries)
    {
        if (!array_is_list($entries) || $entries === []) {
            throw new InvalidArgumentException('An extension archive cannot be empty.');
        }

        foreach ($entries as $entry) {
            if (!($entry instanceof ArchiveEntry)) {
                throw new InvalidArgumentException('Every extension archive entry must be an ArchiveEntry.');
            }
        }

        /** @var list<ArchiveEntry> $entries */
        $this->entries = $entries;
    }

    /** @return list<ArchiveEntry> */
    public function entries(): array
    {
        return $this->entries;
    }
}
