<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use InvalidArgumentException;
use Kumwe\App\Application\Presentation\Preference\PresentationPreferenceRepository;
use Kumwe\App\Application\Presentation\Preference\PresentationPreferenceVersionConflict;
use Kumwe\App\Extension\Contribution\ContributionOwner;
use Kumwe\App\InterfaceStandard\PresentationPreference;
use Kumwe\App\InterfaceStandard\PresentationPreferenceKey;

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
     * Number of exact single-row reads requested by the scenario.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $findCalls = 0;

    /**
     * Number of bounded batch reads requested by the scenario.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $findManyCalls = 0;

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
        $this->findCalls++;

        return $this->records[$key->auditSubjectId()] ?? null;
    }

    /**
     * Read a bounded unique key set without issuing one logical read per key.
     *
     * @param   list<PresentationPreferenceKey>  $keys  Exact fixture identities to select.
     *
     * @return  array<string, PresentationPreference>  Present rows keyed by durable identity.
     *
     * @throws  InvalidArgumentException  When the key set is malformed, duplicated, or unbounded.
     *
     * @since   2.0.0
     */
    public function findMany(array $keys): array
    {
        $this->findManyCalls++;
        if (!array_is_list($keys) || count($keys) > 256) {
            throw new InvalidArgumentException('A preference batch read must be a bounded list.');
        }
        $result = [];
        $seen = [];
        foreach ($keys as $key) {
            if (!$key instanceof PresentationPreferenceKey || isset($seen[$key->auditSubjectId()])) {
                throw new InvalidArgumentException('A preference batch read contains an invalid key.');
            }
            $seen[$key->auditSubjectId()] = true;
            $preference = $this->records[$key->auditSubjectId()] ?? null;
            if ($preference !== null) {
                $result[$key->auditSubjectId()] = $preference;
            }
        }

        return $result;
    }

    /**
     * Report exact and batch read counts for query-budget assertions.
     *
     * @return  array{find: int, find_many: int}  Logical repository read counts.
     *
     * @since   2.0.0
     */
    public function readCounts(): array
    {
        return ['find' => $this->findCalls, 'find_many' => $this->findManyCalls];
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
