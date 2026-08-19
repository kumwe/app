<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Application;

use Kumwe\App\BusinessDefinition\Domain\BuiltInFieldTypes;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;

/**
 * Mutable set of the field types the running process may build business definitions from.
 *
 * The registry is filled once per process — core built-ins first, then one extension's contributions at a
 * time — and is what `BusinessDefinitionValidator` resolves field references against, so a definition can
 * only name a type some present owner vouches for. Registration is ownership-checked and write-once: an
 * identifier belongs to exactly one owner and is never redefined in place, which is what lets `remove()`
 * withdraw an extension's contributions during uninstall without disturbing anyone else's. It resolves the
 * active set only; structure for a withdrawn owner comes from the persisted resolver instead.
 *
 * @since  2.0.0
 */
final class FieldTypeRegistry implements FieldTypeDefinitionResolver
{
    /**
     * Registered structures with the owner that claimed each one, keyed by field-type identifier.
     *
     * Kept sorted by identifier on every registration, so the listing methods are order-stable.
     *
     * @var    array<string, array{owner: DefinitionOwner, definition: FieldTypeDefinition}>
     * @since  2.0.0
     */
    private array $definitions = [];

    /**
     * Start a registry, optionally seeded with the core built-in field types.
     *
     * @param  bool  $withCore  Whether to seed the `core.*` built-ins; false leaves it empty for contributions.
     *
     * @since  2.0.0
     */
    public function __construct(bool $withCore = true)
    {
        if ($withCore) {
            foreach (BuiltInFieldTypes::all() as $definition) {
                $this->register(DefinitionOwner::core(), $definition);
            }
        }
    }

    /**
     * Claim an identifier for one owner and make its structure resolvable.
     *
     * @param   DefinitionOwner      $owner       Contributor claiming the type; its namespace must cover the id.
     * @param   FieldTypeDefinition  $definition  Structure to expose under its own `id`.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the id sits outside the owner namespace or is already claimed.
     *
     * @since   2.0.0
     */
    public function register(DefinitionOwner $owner, FieldTypeDefinition $definition): void
    {
        $owner->assertOwns($definition->id);
        if (isset($this->definitions[$definition->id])) {
            throw new InvalidBusinessDefinition('Field type ' . $definition->id . ' is already registered.');
        }
        $this->definitions[$definition->id] = ['owner' => $owner, 'definition' => $definition];
        ksort($this->definitions, SORT_STRING);
    }

    /**
     * Resolve a field type from the set currently registered in this process.
     *
     * Only the in-memory set is consulted, so a type whose owner has been withdrawn reads as absent here
     * even though its persisted structure still exists.
     *
     * @param   string  $identifier  Namespaced field-type identifier, such as `core.text`.
     *
     * @return  FieldTypeDefinition  The structure its present owner registered.
     *
     * @throws  InvalidBusinessDefinition  When no present owner has claimed that identifier.
     *
     * @since   2.0.0
     */
    public function get(string $identifier): FieldTypeDefinition
    {
        return $this->definitions[$identifier]['definition']
            ?? throw new InvalidBusinessDefinition('Field type ' . $identifier . ' is not active.');
    }

    /**
     * Report whether an identifier is claimed right now.
     *
     * @param   string  $identifier  Namespaced field-type identifier to look for.
     *
     * @return  bool  True when `get()` would resolve it instead of raising.
     *
     * @since   2.0.0
     */
    public function has(string $identifier): bool
    {
        return isset($this->definitions[$identifier]);
    }

    /**
     * List every registered structure, in identifier order.
     *
     * @return  list<FieldTypeDefinition>  Structures only; the owner behind each one is dropped.
     *
     * @since   2.0.0
     */
    public function all(): array
    {
        return array_values(array_map(
            static fn (array $item): FieldTypeDefinition => $item['definition'],
            $this->definitions,
        ));
    }

    /**
     * List the structures one owner contributed, in identifier order.
     *
     * Owners are compared by type and identifier rather than by instance, so a rebuilt owner value matches.
     *
     * @param   DefinitionOwner  $owner  Contributor whose claims are being inventoried.
     *
     * @return  list<FieldTypeDefinition>  Empty when that owner has claimed nothing.
     *
     * @since   2.0.0
     */
    public function ownedBy(DefinitionOwner $owner): array
    {
        return array_values(array_map(
            static fn (array $item): FieldTypeDefinition => $item['definition'],
            array_filter(
                $this->definitions,
                static fn (array $item): bool => $item['owner']->toArray() === $owner->toArray(),
            ),
        ));
    }

    /**
     * Withdraw every structure one owner contributed.
     *
     * Uninstall and a rolled-back install both end here, so removing an owner that holds nothing is a
     * no-op, and entries claimed by other owners are left untouched.
     *
     * @param   DefinitionOwner  $owner  Contributor whose claims leave the registry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(DefinitionOwner $owner): void
    {
        foreach ($this->definitions as $identifier => $item) {
            if ($item['owner']->toArray() === $owner->toArray()) {
                unset($this->definitions[$identifier]);
            }
        }
    }
}
