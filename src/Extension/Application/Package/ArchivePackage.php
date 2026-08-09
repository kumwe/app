<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Package;

use InvalidArgumentException;

/**
 * Immutable description of what an extension archive contains, built without extracting it.
 *
 * `ArchiveReader` assembles one from an archive's directory listing and `PackageSafetyPolicy` judges
 * it, so the install path can decide whether an upload is fit to unpack while none of its bytes have
 * reached the filesystem. The constructor is the only place the entry table is validated, so every
 * reader of `entries()` may assume a non-empty list of `ArchiveEntry`.
 *
 * @since  2.0.0
 */
final readonly class ArchivePackage
{
    /**
     * Entry table of the archive, in the order the reader listed it.
     *
     * @var    list<ArchiveEntry>
     * @since  2.0.0
     */
    private array $entries;

    /**
     * Validate an entry table and freeze it as an archive description.
     *
     * The parameter type is deliberately wide because the caller passes on whatever the archive
     * directory yielded; this constructor is the boundary that proves the shape before any downstream
     * code relies on it.
     *
     * @param   array<mixed>  $entries  Entry descriptions read from the archive directory, in listing order.
     *
     * @throws  InvalidArgumentException  When the value is not a non-empty list of ArchiveEntry objects.
     *
     * @since   2.0.0
     */
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

    /**
     * Return the archive's entry table.
     *
     * @return  list<ArchiveEntry>  Every entry the reader found, in listing order; never empty.
     *
     * @since   2.0.0
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
