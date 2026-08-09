<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Package;

/**
 * Port that lists an extension archive's contents without unpacking any of them.
 *
 * Every install begins here: `DoctrineExtensionManager` inspects the staged upload, hands the result
 * to `PackageSafetyPolicy`, and only then extracts. Keeping inspection behind this port is what lets
 * the size, link, and layout rules be decided in application code while the archive format stays an
 * infrastructure concern — `ZipArchiveReader` is the shipped binding.
 *
 * @since  2.0.0
 */
interface ArchiveReader
{
    /**
     * Read an archive's directory listing into a description the safety policy can judge.
     *
     * Implementations inspect into non-public staging and must not extract before
     * PackageSafetyPolicy has accepted the returned descriptor.
     *
     * @param   string  $archiveFile  Path of the staged archive file to inspect.
     *
     * @return  ArchivePackage  Every entry with its type and its compressed and expanded sizes.
     *
     * @since   2.0.0
     */
    public function inspect(string $archiveFile): ArchivePackage;
}
