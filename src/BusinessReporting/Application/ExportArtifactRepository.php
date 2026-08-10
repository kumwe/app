<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Application;

use Kumwe\CMS\BusinessReporting\Domain\ExportArtifact;

/**
 * Durable compare-and-set ledger for immutable export metadata versions.
 *
 * @since  2.0.0
 */
interface ExportArtifactRepository
{
    /**
     * Insert one new queued export.
     *
     * @param   ExportArtifact  $artifact  Metadata at version one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function add(ExportArtifact $artifact): void;

    /**
     * Resolve metadata by opaque UUID.
     *
     * @param   string  $id  Artifact UUID.
     *
     * @return  ?ExportArtifact  Current state, or null without revealing another scope.
     *
     * @since   2.0.0
     */
    public function find(string $id): ?ExportArtifact;

    /**
     * Replace metadata only when its prior version remains current.
     *
     * @param   ExportArtifact  $artifact         New immutable state.
     * @param   int             $expectedVersion  Version read before the transition.
     *
     * @return  void
     *
     * @throws  ExportVersionConflict  When another worker already changed the artifact.
     *
     * @since   2.0.0
     */
    public function save(ExportArtifact $artifact, int $expectedVersion): void;
}
