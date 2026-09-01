<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\App\Application\Authorization\ResourcePolicyDefinition as AuthorizationResourcePolicyDefinition;
use Kumwe\Extension\Spi\Identity\Domain\Capability;

/**
 * Contribution surface mirroring owner-bound resource policies into the authorization runtime.
 *
 * The outer registry retains manifest wording and inventory shape, while the shared operational
 * registry enforces capability ownership and action/resource collision safety. Both are written in
 * the same registration call, so a policy rejected by the authorization layer never appears active.
 *
 * @since  2.0.0
 */
final class ResourcePolicyDefinitionRegistry implements ContributionSurface
{
    /**
     * Registered contributions keyed by policy identifier.
     *
     * @var    array<string, array{owner: string, definition: ResourcePolicyDefinition}>
     * @since  2.0.0
     */
    private array $definitions = [];

    /**
     * Bind the contribution surface to the canonical operational policy registry.
     *
     * @param  AuthorizationPolicyRegistry  $authorization  Registry shared with capabilities and the gateway.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly AuthorizationPolicyRegistry $authorization)
    {
    }

    /**
     * Register one resource policy for the owner that contributed its capability.
     *
     * @param   ContributionOwner         $owner       Contributor claiming the policy identifier.
     * @param   ResourcePolicyDefinition  $definition  Typed declaration to reconcile and activate.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When ownership, capability ownership, identifier uniqueness,
     *          system-identity authority, or action/resource collision validation fails.
     *
     * @since   2.0.0
     */
    public function register(ContributionOwner $owner, ResourcePolicyDefinition $definition): void
    {
        $owner->assertOwns($definition->id, 'resource policy');
        if (isset($this->definitions[$definition->id])) {
            throw new InvalidArgumentException(sprintf(
                'Resource-policy contribution %s is already owned by %s.',
                $definition->id,
                $this->definitions[$definition->id]['owner'],
            ));
        }

        $this->authorization->registerResourcePolicy(new AuthorizationResourcePolicyDefinition(
            $definition->id,
            $owner->identifier(),
            Capability::fromString($definition->capability),
            $definition->resources,
            $definition->installationGlobal,
            $definition->systemIdentities,
            $definition->lifecycle,
            $definition->version,
        ));
        $this->definitions[$definition->id] = [
            'owner' => $owner->identifier(),
            'definition' => $definition,
        ];
    }

    /**
     * Whether one owner currently holds the policy identifier.
     *
     * @param   string             $identifier  Policy identifier being referenced.
     * @param   ContributionOwner  $owner       Contributor expected to own it.
     *
     * @return  bool  True only when that exact owner registered the policy.
     *
     * @since   2.0.0
     */
    public function isOwnedBy(string $identifier, ContributionOwner $owner): bool
    {
        return ($this->definitions[$identifier]['owner'] ?? null) === $owner->identifier();
    }

    /**
     * List resource-policy declarations belonging to one owner.
     *
     * @param   ContributionOwner  $owner  Contributor whose policies are being inventoried.
     *
     * @return  list<array<string, mixed>>  Deterministic declaration exports for that owner.
     *
     * @since   2.0.0
     */
    public function ownedBy(ContributionOwner $owner): array
    {
        $definitions = $this->definitions;
        ksort($definitions, SORT_STRING);
        $owned = [];
        foreach ($definitions as $entry) {
            if ($entry['owner'] === $owner->identifier()) {
                $owned[] = $entry['definition']->toArray();
            }
        }

        return $owned;
    }

    /**
     * Withdraw every policy belonging to one owner from both registry views.
     *
     * @param   ContributionOwner  $owner  Contributor being disabled, removed, or made untrusted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(ContributionOwner $owner): void
    {
        $this->authorization->resourcePolicies()->removeOwner($owner->identifier());
        foreach ($this->definitions as $identifier => $entry) {
            if ($entry['owner'] === $owner->identifier()) {
                unset($this->definitions[$identifier]);
            }
        }
    }
}
