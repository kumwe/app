<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use Kumwe\App\Studio\Application\Host\StudioPersistenceRace;
use Kumwe\App\Studio\Domain\Projection\EntryCompositionOverrides;

/**
 * Write-only port for pinning one Content entry's item layout revision.
 *
 * Kept separate from the read-only projection port so model reads cannot acquire mutation authority;
 * only StudioContentCompositionService::keepItemLayout(), reached by the audited Save item and by type saves
 * from an item with its own layout, moves the pointer.
 *
 * @since  2.0.0
 */
interface EntryCompositionOverrideStore
{
    /**
     * Insert the entry's override record, or move its item layout pointer by compare-and-set.
     *
     * With no expected revision the record is inserted, carrying the supplied override values. Otherwise
     * only the item layout revision and the override revision move, and only while the stored override
     * revision still equals the expected one; stored override values are never rewritten. The caller
     * must hold an active transaction.
     *
     * @param   EntryCompositionOverrides  $next              Record carrying the next pointer and revision.
     * @param   ?int                       $expectedRevision  Stored override revision, or null to insert.
     *
     * @return  void
     *
     * @throws  StudioPersistenceRace  When the record was inserted or moved concurrently.
     *
     * @since   2.0.0
     */
    public function pin(EntryCompositionOverrides $next, ?int $expectedRevision): void;
}
