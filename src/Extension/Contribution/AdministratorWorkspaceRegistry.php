<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;

final class AdministratorWorkspaceRegistry implements ContributionSurface
{
    /** @var array<string, array{owner: string, definition: AdministratorWorkspaceDefinition}> */
    private array $definitions = [];

    public function register(ContributionOwner $owner, AdministratorWorkspaceDefinition $definition): void
    {
        $owner->assertOwns($definition->id, 'workspace');
        if (isset($this->definitions[$definition->id])) {
            throw new InvalidArgumentException(sprintf(
                'Administrator workspace %s is already owned by %s.',
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

    public function definition(string $identifier): AdministratorWorkspaceDefinition
    {
        return $this->definitions[$identifier]['definition']
            ?? throw new InvalidArgumentException('The contributed administrator workspace is not registered.');
    }

    /** @return list<array<string, mixed>> */
    public function ownedBy(ContributionOwner $owner): array
    {
        $result = [];
        foreach ($this->definitions as $entry) {
            if ($entry['owner'] === $owner->identifier()) {
                $result[] = $entry['definition']->toArray();
            }
        }
        return $result;
    }

    public function remove(ContributionOwner $owner): void
    {
        foreach ($this->definitions as $identifier => $entry) {
            if ($entry['owner'] === $owner->identifier()) {
                unset($this->definitions[$identifier]);
            }
        }
    }
}
