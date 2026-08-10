<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Development;

/**
 * Result of an atomically published deterministic extension archive.
 *
 * @since  2.0.0
 */
final readonly class PackageBuildResult
{
    /**
     * Retain the output path and its post-publication production inspection.
     *
     * @param  string             $archive     Canonical absolute output path.
     * @param  PackageInspection  $inspection  Inspection proving the published package is install-safe.
     *
     * @since  2.0.0
     */
    public function __construct(public string $archive, public PackageInspection $inspection)
    {
    }

    /**
     * Export the build identity without duplicating the full manifest inventory.
     *
     * @return  array{archive: string, package_sha256: string, entry_count: int}  Stable build summary.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'archive' => $this->archive,
            'package_sha256' => (string) $this->inspection->checksum,
            'entry_count' => count($this->inspection->paths),
        ];
    }
}
