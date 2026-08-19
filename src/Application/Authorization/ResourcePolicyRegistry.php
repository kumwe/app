<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use InvalidArgumentException;
use Kumwe\App\Identity\Domain\Capability;

/**
 * Collision-safe owner-aware registry of capability-to-resource policy bindings.
 *
 * A policy is accepted only after its capability has been registered by the same owner, and no two
 * definitions may overlap for one capability. Those invariants leave every action/resource request
 * with at most one typed base binding and prevent an extension from attaching policy to another
 * package's capability namespace.
 *
 * @since  2.0.0
 */
final class ResourcePolicyRegistry
{
    /**
     * Policy definitions keyed by their owner-namespaced identifier.
     *
     * @var    array<string, ResourcePolicyDefinition>
     * @since  2.0.0
     */
    private array $definitions = [];

    /**
     * Bind the policy registry to the capability catalog it validates references against.
     *
     * @param  CapabilityDefinitionRegistry  $capabilities  Canonical live capability definitions.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly CapabilityDefinitionRegistry $capabilities)
    {
    }

    /**
     * Register one base resource-policy binding.
     *
     * @param   ResourcePolicyDefinition  $definition  Validated owner-bound policy to add.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the id is taken, the capability is missing or foreign,
     *          or another policy already covers any of the same resources for the capability.
     *
     * @since   2.0.0
     */
    public function register(ResourcePolicyDefinition $definition): void
    {
        if (isset($this->definitions[$definition->id])) {
            throw new InvalidArgumentException(sprintf(
                'Authorization resource policy %s is already owned by %s.',
                $definition->id,
                $this->definitions[$definition->id]->owner,
            ));
        }

        $capability = $this->capabilities->definition($definition->capability);
        if ($capability === null || $capability->owner !== $definition->owner) {
            throw new InvalidArgumentException(sprintf(
                'Resource policy %s must reference a capability owned by %s.',
                $definition->id,
                $definition->owner,
            ));
        }

        foreach ($this->definitions as $registered) {
            if ($registered->overlaps($definition)) {
                throw new InvalidArgumentException(sprintf(
                    'Resource policy %s overlaps registered policy %s.',
                    $definition->id,
                    $registered->id,
                ));
            }
        }

        $this->definitions[$definition->id] = $definition;
    }

    /**
     * Resolve the single enforceable policy binding an action/resource pair matches.
     *
     * @param   Capability             $capability  Action being exercised.
     * @param   AuthorizationResource  $resource    Target the action is aimed at.
     *
     * @return  ?ResourcePolicyDefinition  Matching policy, or null when the pair is unsupported.
     *
     * @since   2.0.0
     */
    public function definitionFor(
        Capability $capability,
        AuthorizationResource $resource,
    ): ?ResourcePolicyDefinition {
        foreach ($this->definitions as $definition) {
            if (
                $definition->enforceable()
                && $definition->capability->equals($capability)
                && $definition->matches($resource)
            ) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * List every enforceable resource binding for one capability.
     *
     * Callers that derive credential constraints need the complete typed target set, including
     * extension-owned definitions, rather than namespace conventions or a second resource catalog.
     *
     * @param   Capability  $capability  Capability whose live bindings are being inspected.
     *
     * @return  list<ResourcePolicyDefinition>  Enforceable bindings in deterministic policy-id order.
     *
     * @since   2.0.0
     */
    public function definitionsFor(Capability $capability): array
    {
        $matching = array_filter(
            $this->definitions,
            static fn (ResourcePolicyDefinition $definition): bool =>
                $definition->capability->equals($capability) && $definition->enforceable(),
        );
        ksort($matching, SORT_STRING);

        return array_values($matching);
    }

    /**
     * List the policy definitions one owner currently holds, ordered by policy identifier.
     *
     * @param   string  $owner  Definition owner being inventoried.
     *
     * @return  list<ResourcePolicyDefinition>  Matching definitions in deterministic identifier order.
     *
     * @since   2.0.0
     */
    public function ownedBy(string $owner): array
    {
        $owned = array_filter(
            $this->definitions,
            static fn (ResourcePolicyDefinition $definition): bool => $definition->owner === $owner,
        );
        ksort($owned, SORT_STRING);

        return array_values($owned);
    }

    /**
     * Withdraw every resource-policy definition belonging to one owner.
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
