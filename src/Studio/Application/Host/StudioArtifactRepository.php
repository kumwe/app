<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;

/**
 * Persistence port for current and immutable historical Studio artifact revisions.
 *
 * @since  2.0.0
 */
interface StudioArtifactRepository
{
    /**
     * Load the current artifact head within one trusted site.
     *
     * @param   string  $siteIdentifier  Trusted site scope.
     * @param   string  $id              Canonical artifact identifier.
     * @param   string  $version         Canonical artifact version.
     *
     * @return  StoredStudioArtifact|null  Current artifact or null when absent.
     *
     * @since   2.0.0
     */
    public function current(string $siteIdentifier, string $id, string $version): ?StoredStudioArtifact;

    /**
     * Load one immutable historical artifact revision within one trusted site.
     *
     * @param   string  $siteIdentifier  Trusted site scope.
     * @param   string  $id              Canonical artifact identifier.
     * @param   string  $version         Canonical artifact version.
     * @param   string  $revision        Exact historical revision.
     *
     * @return  StoredStudioArtifact|null  Historical artifact or null when absent.
     *
     * @since   2.0.0
     */
    public function revision(
        string $siteIdentifier,
        string $id,
        string $version,
        string $revision,
    ): ?StoredStudioArtifact;

    /**
     * Append an immutable revision and move the head only when its current revision still matches.
     *
     * @param   StoredStudioArtifact  $artifact         Next admitted revision.
     * @param   string|null           $expectedCurrent  Current revision, null only for first creation.
     *
     * @return  bool  False when the head changed before the compare-and-set.
     *
     * @since   2.0.0
     */
    public function store(StoredStudioArtifact $artifact, ?string $expectedCurrent): bool;
}
