<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;

/**
 * The capability identifiers the running process recognises, each held by exactly one owner.
 *
 * Administrator navigation and administrator routes may only be guarded by a capability their own
 * owner registered, and `isOwnedBy()` is what those checks resolve against — so this registry is
 * what stops one extension from hanging its screens off another's permissions. An identifier is
 * claimed once and never redefined in place, which is what lets `remove()` withdraw a contributor's
 * capabilities wholesale on disable or uninstall without disturbing anyone else's.
 *
 * @since  2.0.0
 */
final class CapabilityDefinitionRegistry implements ContributionSurface
{
    /**
     * Registered capabilities with the owner identifier that claimed each one, keyed by capability id.
     *
     * @var    array<string, array{owner: string, definition: CapabilityDefinition}>
     * @since  2.0.0
     */
    private array $definitions = [];

    /**
     * Claim one capability identifier for one owner.
     *
     * @param   ContributionOwner     $owner       Contributor claiming it; its namespace must cover the id.
     * @param   CapabilityDefinition  $definition  Capability being contributed, already validated.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the owner may not claim the id, or someone already holds it.
     *
     * @since   2.0.0
     */
    public function register(ContributionOwner $owner, CapabilityDefinition $definition): void
    {
        $owner->assertOwns($definition->id, 'capability');
        if (isset($this->definitions[$definition->id])) {
            throw new InvalidArgumentException(sprintf(
                'Capability contribution %s is already owned by %s.',
                $definition->id,
                $this->definitions[$definition->id]['owner'],
            ));
        }
        $this->definitions[$definition->id] = [
            'owner' => $owner->identifier(),
            'definition' => $definition,
        ];
    }

    /**
     * Whether one owner is the contributor that registered a given capability.
     *
     * Route and navigation registration ask this before accepting a contribution, so a false answer
     * is the point at which a screen guarded by someone else's capability is refused.
     *
     * @param   string             $identifier  Capability identifier a contribution wants to reference.
     * @param   ContributionOwner  $owner       Contributor that has to hold it.
     *
     * @return  bool  False both when no such capability is registered and when another owner holds it.
     *
     * @since   2.0.0
     */
    public function isOwnedBy(string $identifier, ContributionOwner $owner): bool
    {
        return ($this->definitions[$identifier]['owner'] ?? null) === $owner->identifier();
    }

    /**
     * List the capabilities one owner contributed.
     *
     * @param   ContributionOwner  $owner  Contributor whose capabilities are being inspected.
     *
     * @return  list<array<string, mixed>>  Array exports in registration order; empty when it claimed none.
     *
     * @since   2.0.0
     */
    public function ownedBy(ContributionOwner $owner): array
    {
        return $this->owned($owner, $this->definitions);
    }

    /**
     * Release every capability identifier one owner claimed.
     *
     * Only this registry's entries are touched, so the registry set withdraws the routes and
     * navigation items that reference them first; a later reinstall can then claim the names again.
     *
     * @param   ContributionOwner  $owner  Contributor being disabled, uninstalled, or untrusted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(ContributionOwner $owner): void
    {
        foreach ($this->definitions as $identifier => $entry) {
            if ($entry['owner'] === $owner->identifier()) {
                unset($this->definitions[$identifier]);
            }
        }
    }

    /**
     * Reduce registry entries to the array exports belonging to one owner.
     *
     * @param   ContributionOwner                                                      $owner    Owner to match.
     * @param   array<string, array{owner: string, definition: CapabilityDefinition}>  $entries  Entries to scan.
     *
     * @return  list<array<string, mixed>>  Exports of the matching entries, in the order they were scanned.
     *
     * @since   2.0.0
     */
    private function owned(ContributionOwner $owner, array $entries): array
    {
        $result = [];
        foreach ($entries as $entry) {
            if ($entry['owner'] === $owner->identifier()) {
                $result[] = $entry['definition']->toArray();
            }
        }
        return $result;
    }
}
