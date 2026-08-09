<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Domain\Capability;

/**
 * Owner-aware operational catalog of every capability the running authorization layer recognises.
 *
 * Each identifier can be claimed once. Removal is by owner, which lets extension disable and trust
 * revocation make stored grants dormant without guessing which rows mention that package. The
 * registry stores the complete typed metadata rather than a second action list in the gateway.
 *
 * @since  2.0.0
 */
final class CapabilityDefinitionRegistry
{
    /**
     * Definitions keyed by their normalized capability identifier.
     *
     * @var    array<string, CapabilityDefinition>
     * @since  2.0.0
     */
    private array $definitions = [];

    /**
     * Claim one capability identifier for its declared owner.
     *
     * @param   CapabilityDefinition  $definition  Validated operational metadata to add.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When any owner already registered the identifier.
     *
     * @since   2.0.0
     */
    public function register(CapabilityDefinition $definition): void
    {
        $identifier = $definition->capability->value();
        if (isset($this->definitions[$identifier])) {
            throw new InvalidArgumentException(sprintf(
                'Authorization capability %s is already owned by %s.',
                $identifier,
                $this->definitions[$identifier]->owner,
            ));
        }

        $this->definitions[$identifier] = $definition;
    }

    /**
     * Resolve a capability's operational definition.
     *
     * @param   Capability  $capability  Normalized capability being evaluated.
     *
     * @return  ?CapabilityDefinition  Its definition, or null when no active owner registered it.
     *
     * @since   2.0.0
     */
    public function definition(Capability $capability): ?CapabilityDefinition
    {
        return $this->definitions[$capability->value()] ?? null;
    }

    /**
     * Whether the named owner currently holds a capability identifier.
     *
     * @param   Capability  $capability  Capability whose ownership is being checked.
     * @param   string      $owner       Expected `core` or `vendor/name` owner.
     *
     * @return  bool  True only when the registered definition belongs to that exact owner.
     *
     * @since   2.0.0
     */
    public function isOwnedBy(Capability $capability, string $owner): bool
    {
        return ($this->definitions[$capability->value()]->owner ?? null) === $owner;
    }

    /**
     * List the definitions one owner currently holds, ordered by capability identifier.
     *
     * @param   string  $owner  Definition owner being inventoried.
     *
     * @return  list<CapabilityDefinition>  Matching definitions in deterministic identifier order.
     *
     * @since   2.0.0
     */
    public function ownedBy(string $owner): array
    {
        $owned = array_filter(
            $this->definitions,
            static fn (CapabilityDefinition $definition): bool => $definition->owner === $owner,
        );
        ksort($owned, SORT_STRING);

        return array_values($owned);
    }

    /**
     * Withdraw every capability belonging to one owner.
     *
     * Resource policies must be removed first by the composite policy registry, so no surviving
     * policy can reference a capability this method has withdrawn.
     *
     * @param   string  $owner  Owner being disabled, removed, or made untrusted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function removeOwner(string $owner): void
    {
        foreach ($this->definitions as $identifier => $definition) {
            if ($definition->owner === $owner) {
                unset($this->definitions[$identifier]);
            }
        }
    }
}
