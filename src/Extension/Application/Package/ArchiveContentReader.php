<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Package;

/**
 * Port that streams an extension archive's file contents without writing any of them to disk.
 *
 * `ArchiveReader` answers what an archive claims to contain; this answers what it actually contains,
 * and it exists because install-time admission has to read packaged bytes before extraction rather
 * than after it. Nothing an implementation yields has touched the filesystem, so a package refused by
 * `PackageAdmissionScanner` leaves no tree behind to clean up. Reading is deliberately separate from
 * listing: the safety policy must have accepted the entry table, and therefore the per-entry and total
 * expansion budgets, before a single entry is expanded through this port.
 *
 * @since  2.0.0
 */
interface ArchiveContentReader
{
    /**
     * Yield every regular file entry as a path and its complete expanded bytes.
     *
     * Directory entries are skipped, since they carry no content to inspect. Implementations expand one
     * entry at a time so peak memory tracks the largest entry rather than the whole archive.
     *
     * @param   string  $archiveFile  Path of the staged archive to read; must be the same bytes the
     *          entry table was judged from.
     *
     * @return  iterable<string, string>  Complete entry bytes keyed by package path, in listing order.
     *
     * @since   2.0.0
     */
    public function contents(string $archiveFile): iterable;
}
