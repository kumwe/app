<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application\Preference;

use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\PresentationPreference;
use Kumwe\CMS\InterfaceStandard\PresentationPreferenceKey;

/**
 * Persistence boundary for versioned KIS presentation preferences.
 *
 * Implementations compare versions in the write or delete statement itself. A preliminary read is never
 * sufficient for concurrency, and a mismatch must fail rather than overwrite a newer customization.
 *
 * @since  2.0.0
 */
interface PresentationPreferenceRepository
{
    /**
     * Find one exact customization layer record.
     *
     * @param   PresentationPreferenceKey  $key  Complete durable identity to select.
     *
     * @return  ?PresentationPreference  Stored record, or null when this layer has no override.
     *
     * @since   2.0.0
     */
    public function find(PresentationPreferenceKey $key): ?PresentationPreference;

    /**
     * Insert or compare-and-swap one preference.
     *
     * An expected version of zero admits only an insert at version one. A positive expected version
     * admits only its exact successor, updated atomically from that stored version.
     *
     * @param   PresentationPreference  $preference       Next complete record to persist.
     * @param   int                     $expectedVersion  Zero for creation, otherwise the version being replaced.
     *
     * @return  void
     *
     * @throws  PresentationPreferenceVersionConflict  When another writer changed or removed the record.
     *
     * @since   2.0.0
     */
    public function save(PresentationPreference $preference, int $expectedVersion): void;

    /**
     * Delete one exact stored version so reset reveals the next valid hierarchy layer.
     *
     * @param   PresentationPreferenceKey  $key              Complete durable identity to delete.
     * @param   ContributionOwner          $expectedOwner    Owner observed with the removable record.
     * @param   int                        $expectedVersion  Positive version the caller last observed.
     *
     * @return  void
     *
     * @throws  PresentationPreferenceVersionConflict  When the record is absent or its version moved.
     *
     * @since   2.0.0
     */
    public function delete(
        PresentationPreferenceKey $key,
        ContributionOwner $expectedOwner,
        int $expectedVersion,
    ): void;
}
