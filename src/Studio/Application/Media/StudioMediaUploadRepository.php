<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

use Kumwe\App\Studio\Domain\Media\StudioMediaUploadSession;

/**
 * Durable, compare-and-swap store for Studio upload grants and their canonical lifecycle.
 *
 * @since  2.0.0
 */
interface StudioMediaUploadRepository
{
    /**
     * Insert a newly authorized upload before its capability is returned.
     *
     * @param   StudioMediaUploadSession  $session  Complete immutable session snapshot.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function add(StudioMediaUploadSession $session): void;

    /**
     * Resolve an upload only inside the trusted actor, site, resource-context and generation scope.
     *
     * @param   string  $id          Opaque upload identity.
     * @param   string  $actorId     Trusted actor identity.
     * @param   string  $siteId      Trusted site identity.
     * @param   string  $contextKey  Trusted Studio resource-context key.
     * @param   string  $generation  Current Studio session generation.
     *
     * @return  StudioMediaUploadSession|null  Revalidated snapshot, or null without existence disclosure.
     *
     * @since   2.0.0
     */
    public function find(
        string $id,
        string $actorId,
        string $siteId,
        string $contextKey,
        string $generation,
    ): ?StudioMediaUploadSession;

    /**
     * Replace one snapshot only while its persisted version still matches.
     *
     * @param   StudioMediaUploadSession  $session          Replacement snapshot.
     * @param   int                       $expectedVersion  Previously observed version.
     *
     * @return  bool  True only when exactly one row advanced.
     *
     * @since   2.0.0
     */
    public function save(StudioMediaUploadSession $session, int $expectedVersion): bool;
}
