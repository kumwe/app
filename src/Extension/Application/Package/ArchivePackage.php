<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Package;

use InvalidArgumentException;

final readonly class ArchivePackage
{
    /** @var list<ArchiveEntry> */
    private array $entries;

    /** @param list<ArchiveEntry> $entries */
    public function __construct(array $entries)
    {
        if ($entries === []) {
            throw new InvalidArgumentException('An extension archive cannot be empty.');
        }

        foreach ($entries as $entry) {
            if (!$entry instanceof ArchiveEntry) {
                throw new InvalidArgumentException('Every archive member must be an ArchiveEntry.');
            }
        }

        $this->entries = array_values($entries);
    }

    /** @return list<ArchiveEntry> */
    public function entries(): array
    {
        return $this->entries;
    }
}
