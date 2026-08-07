<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use Kumwe\CMS\BusinessDefinition\Domain\BuiltInFieldTypes;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;

final class FieldTypeRegistry
{
    /** @var array<string, array{owner: DefinitionOwner, definition: FieldTypeDefinition}> */
    private array $definitions = [];

    public function __construct(bool $withCore = true)
    {
        if ($withCore) {
            foreach (BuiltInFieldTypes::all() as $definition) {
                $this->register(DefinitionOwner::core(), $definition);
            }
        }
    }

    public function register(DefinitionOwner $owner, FieldTypeDefinition $definition): void
    {
        $owner->assertOwns($definition->id);
        if (isset($this->definitions[$definition->id])) {
            throw new InvalidBusinessDefinition('Field type ' . $definition->id . ' is already registered.');
        }
        $this->definitions[$definition->id] = ['owner' => $owner, 'definition' => $definition];
        ksort($this->definitions, SORT_STRING);
    }

    public function get(string $identifier): FieldTypeDefinition
    {
        return $this->definitions[$identifier]['definition']
            ?? throw new InvalidBusinessDefinition('Field type ' . $identifier . ' is not active.');
    }

    public function has(string $identifier): bool
    {
        return isset($this->definitions[$identifier]);
    }

    /** @return list<FieldTypeDefinition> */
    public function all(): array
    {
        return array_values(array_map(
            static fn (array $item): FieldTypeDefinition => $item['definition'],
            $this->definitions,
        ));
    }

    /** @return list<FieldTypeDefinition> */
    public function ownedBy(DefinitionOwner $owner): array
    {
        return array_values(array_map(
            static fn (array $item): FieldTypeDefinition => $item['definition'],
            array_filter(
                $this->definitions,
                static fn (array $item): bool => $item['owner'] == $owner,
            ),
        ));
    }

    public function remove(DefinitionOwner $owner): void
    {
        foreach ($this->definitions as $identifier => $item) {
            if ($item['owner'] == $owner) {
                unset($this->definitions[$identifier]);
            }
        }
    }
}
