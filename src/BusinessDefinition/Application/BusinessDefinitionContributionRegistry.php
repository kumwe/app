<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Application;

use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;

/**
 * Collects the entity types core and extensions contribute during bootstrap, to be checked as one graph.
 *
 * A contributed definition may reference entity types and field types another package ships, so no single
 * provider can be validated on its own; contributions accumulate here and are checked together once the
 * last provider has run. Registration is where ownership is enforced — a contributor may claim only
 * handles inside its own owner namespace, its definition must name the same owner that is registering it,
 * and a handle already claimed is refused rather than overwritten — which is what stops one extension from
 * shadowing another's entity type. Entries are kept in handle order so the validated set does not depend
 * on the order providers happened to run in, and `remove()` withdraws an owner's contributions when its
 * extension is disabled, uninstalled or loses trust.
 *
 * @since  2.0.0
 */
final class BusinessDefinitionContributionRegistry
{
    /**
     * Contributions in handle order, each definition paired with the owner that registered it.
     *
     * @var    array<string, array{owner: DefinitionOwner, definition: EntityTypeDefinition}>
     * @since  2.0.0
     */
    private array $definitions = [];

    /**
     * Bind the registry to the validator that will check the assembled graph.
     *
     * @param  BusinessDefinitionValidator  $validator  Checks the contributed set once every provider ran.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly BusinessDefinitionValidator $validator)
    {
    }

    /**
     * Take one contributed entity type into the set, refusing anything the contributor may not claim.
     *
     * Both ownership checks matter: the handle has to sit inside the contributor's namespace, and the owner
     * recorded inside the definition has to be the contributor itself, so a package cannot ship a
     * definition attributed to somebody else. Nothing about the definition's references is checked yet —
     * that waits for `validate()`, when the rest of the graph exists.
     *
     * @param   DefinitionOwner       $owner       Contributor registering the definition, core or extension.
     * @param   EntityTypeDefinition  $definition  Entity type being contributed this process.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the handle falls outside the contributor's owner namespace,
     *          the definition names a different owner, or the handle is already registered.
     *
     * @since   2.0.0
     */
    public function register(DefinitionOwner $owner, EntityTypeDefinition $definition): void
    {
        $owner->assertOwns($definition->handle);
        if ($definition->owner->toArray() !== $owner->toArray()) {
            throw new InvalidBusinessDefinition('A contributed business definition has inconsistent ownership.');
        }
        if (isset($this->definitions[$definition->handle])) {
            throw new InvalidBusinessDefinition(
                'Business definition ' . $definition->handle . ' is already registered.',
            );
        }
        $this->definitions[$definition->handle] = ['owner' => $owner, 'definition' => $definition];
        ksort($this->definitions, SORT_STRING);
    }

    /**
     * Check everything contributed so far as a single graph.
     *
     * Cross-package references resolve only once every provider has run, so this is driven by whoever owns
     * the contribution phase rather than folded into `register()`. A process that contributed nothing does
     * no work here, because the validator treats an empty graph as an error rather than a trivial pass.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the contributed set is not a valid graph — an unresolvable
     *          entity or field-type reference, unsupported field configuration, or more than 128 entities.
     *
     * @since   2.0.0
     */
    public function validate(): void
    {
        if ($this->definitions !== []) {
            $this->validator->validateGraph($this->all());
        }
    }

    /**
     * Return every entity type contributed this process, whoever contributed it.
     *
     * @return  list<EntityTypeDefinition>  In handle order, so the order providers ran in does not leak into
     *          the validated graph or anything derived from it.
     *
     * @since   2.0.0
     */
    public function all(): array
    {
        return array_values(array_map(
            static fn (array $item): EntityTypeDefinition => $item['definition'],
            $this->definitions,
        ));
    }

    /**
     * Return only the entity types one contributor registered.
     *
     * @param   DefinitionOwner  $owner  Contributor to report on, matched on owner type and identifier.
     *
     * @return  list<EntityTypeDefinition>  In handle order; empty when that contributor registered nothing.
     *
     * @since   2.0.0
     */
    public function ownedBy(DefinitionOwner $owner): array
    {
        return array_values(array_map(
            static fn (array $item): EntityTypeDefinition => $item['definition'],
            array_filter(
                $this->definitions,
                static fn (array $item): bool => $item['owner']->toArray() === $owner->toArray(),
            ),
        ));
    }

    /**
     * Withdraw everything one contributor registered.
     *
     * Driven when an extension is disabled, uninstalled or loses trust, so its entity types stop reaching
     * the graph the rest of the process validates and reads. Removing an owner that holds nothing is a
     * silent no-op, which lets lifecycle code withdraw a package without first asking what it contributed.
     *
     * @param   DefinitionOwner  $owner  Contributor whose entity types are being withdrawn.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(DefinitionOwner $owner): void
    {
        foreach ($this->definitions as $handle => $item) {
            if ($item['owner']->toArray() === $owner->toArray()) {
                unset($this->definitions[$handle]);
            }
        }
    }
}
