<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Package;

interface ArchiveReader
{
    /**
     * Implementations inspect into non-public staging and must not extract before
     * PackageSafetyPolicy has accepted the returned descriptor.
     */
    public function inspect(string $archiveFile): ArchivePackage;
}
