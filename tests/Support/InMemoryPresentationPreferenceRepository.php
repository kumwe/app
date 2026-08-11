<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Support;

use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\PresentationPreference;
use Kumwe\CMS\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceRepository;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceVersionConflict;

/**
 * Deterministic in-memory preference repository for application and resolver tests.
 *
 * @since  2.0.0
 */
final class InMemoryPresentationPreferenceRepository implements PresentationPreferenceRepository
{
    /**
     * Records keyed by a digest of their durable identity.
     *
     * @var    array<string, PresentationPreference>
     * @since  2.0.0
     */
    private array $records = [];

    /**
     * Insert a fixture record without optimistic mutation behavior.
     *
     * @param   PresentationPreference  $preference  Fixture to make discoverable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function seed(PresentationPreference $preference): void
    {
        $key = PresentationPreferenceKey::fromPreference($preference);
        $this->records[$key->auditSubjectId()] = $preference;
    }

    /**
     * Find one fixture record.
     *
     * @param   PresentationPreferenceKey  $key  Durable identity.
     *
     * @return  ?PresentationPreference
     *
     * @since   2.0.0
     */
    public function find(PresentationPreferenceKey $key): ?PresentationPreference
    {
        return $this->records[$key->auditSubjectId()] ?? null;
    }

    /**
     * Compare-and-swap one fixture record.
     *
     * @param   PresentationPreference  $preference       Next record.
     * @param   int                     $expectedVersion  Expected stored version.
     *
     * @return  void
     *
     * @throws  PresentationPreferenceVersionConflict  When the fixture version moved.
     *
     * @since   2.0.0
     */
    public function save(PresentationPreference $preference, int $expectedVersion): void
    {
        $key = PresentationPreferenceKey::fromPreference($preference);
        $current = $this->find($key);
        $actual = $current?->version() ?? 0;
        if ($actual !== $expectedVersion || $preference->version() !== $expectedVersion + 1) {
            throw new PresentationPreferenceVersionConflict($key, $expectedVersion, $actual);
        }
        $this->records[$key->auditSubjectId()] = $preference;
    }

    /**
     * Delete one exact fixture version.
     *
     * @param   PresentationPreferenceKey  $key              Durable identity.
     * @param   ContributionOwner          $expectedOwner    Expected stored owner.
     * @param   int                        $expectedVersion  Expected stored version.
     *
     * @return  void
     *
     * @throws  PresentationPreferenceVersionConflict  When the fixture version moved.
     *
     * @since   2.0.0
     */
    public function delete(
        PresentationPreferenceKey $key,
        ContributionOwner $expectedOwner,
        int $expectedVersion,
    ): void {
        $current = $this->find($key);
        $actual = $current?->version() ?? 0;
        if ($actual !== $expectedVersion || $current?->owner()->identifier() !== $expectedOwner->identifier()) {
            throw new PresentationPreferenceVersionConflict($key, $expectedVersion, $actual);
        }
        unset($this->records[$key->auditSubjectId()]);
    }
}
