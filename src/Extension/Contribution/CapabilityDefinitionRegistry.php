<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;

final class CapabilityDefinitionRegistry implements ContributionSurface
{
    /** @var array<string, array{owner: string, definition: CapabilityDefinition}> */
    private array $definitions = [];

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

    public function isOwnedBy(string $identifier, ContributionOwner $owner): bool
    {
        return ($this->definitions[$identifier]['owner'] ?? null) === $owner->identifier();
    }

    /** @return list<array<string, mixed>> */
    public function ownedBy(ContributionOwner $owner): array
    {
        return $this->owned($owner, $this->definitions);
    }

    public function remove(ContributionOwner $owner): void
    {
        foreach ($this->definitions as $identifier => $entry) {
            if ($entry['owner'] === $owner->identifier()) {
                unset($this->definitions[$identifier]);
            }
        }
    }

    /**
     * @param array<string, array{owner: string, definition: CapabilityDefinition}> $entries
     * @return list<array<string, mixed>>
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
