<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;

final class BusinessDefinitionContributionRegistry
{
    /** @var array<string, array{owner: DefinitionOwner, definition: EntityTypeDefinition}> */
    private array $definitions = [];

    public function __construct(private readonly BusinessDefinitionValidator $validator)
    {
    }

    public function register(DefinitionOwner $owner, EntityTypeDefinition $definition): void
    {
        $owner->assertOwns($definition->handle);
        if ($definition->owner != $owner) {
            throw new InvalidBusinessDefinition('A contributed business definition has inconsistent ownership.');
        }
        if (isset($this->definitions[$definition->handle])) {
            throw new InvalidBusinessDefinition('Business definition ' . $definition->handle . ' is already registered.');
        }
        $this->definitions[$definition->handle] = ['owner' => $owner, 'definition' => $definition];
        ksort($this->definitions, SORT_STRING);
    }

    public function validate(): void
    {
        if ($this->definitions !== []) {
            $this->validator->validateGraph($this->all());
        }
    }

    /** @return list<EntityTypeDefinition> */
    public function all(): array
    {
        return array_values(array_map(
            static fn (array $item): EntityTypeDefinition => $item['definition'],
            $this->definitions,
        ));
    }

    /** @return list<EntityTypeDefinition> */
    public function ownedBy(DefinitionOwner $owner): array
    {
        return array_values(array_map(
            static fn (array $item): EntityTypeDefinition => $item['definition'],
            array_filter(
                $this->definitions,
                static fn (array $item): bool => $item['owner'] == $owner,
            ),
        ));
    }

    public function remove(DefinitionOwner $owner): void
    {
        foreach ($this->definitions as $handle => $item) {
            if ($item['owner'] == $owner) {
                unset($this->definitions[$handle]);
            }
        }
    }
}
