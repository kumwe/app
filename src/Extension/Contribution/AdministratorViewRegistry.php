<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;

final class AdministratorViewRegistry implements ContributionSurface
{
    /** @var array<string, array{owner: string, definition: AdministratorViewDefinition}> */
    private array $definitions = [];

    public function register(ContributionOwner $owner, AdministratorViewDefinition $definition): void
    {
        $owner->assertOwns($definition->name, 'view');
        if (isset($this->definitions[$definition->name])) {
            throw new InvalidArgumentException(sprintf(
                'Administrator view %s is already owned by %s.',
                $definition->name,
                $this->definitions[$definition->name]['owner'],
            ));
        }
        $this->definitions[$definition->name] = [
            'owner' => $owner->identifier(),
            'definition' => $definition,
        ];
    }

    public function isOwnedBy(string $identifier, ContributionOwner $owner): bool
    {
        return ($this->definitions[$identifier]['owner'] ?? null) === $owner->identifier();
    }

    public function template(ContributionOwner $owner, string $view): string
    {
        if (!$this->isOwnedBy($view, $owner)) {
            throw new InvalidArgumentException('An extension cannot render an unowned administrator view.');
        }
        return $this->definitions[$view]['definition']->template;
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
