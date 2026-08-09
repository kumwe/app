<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\GrantScope;

/**
 * Canonical live registry of capability metadata and owner-bound resource policies.
 *
 * Core and extensions populate this same mutable bootstrap registry through their owner-bound
 * contribution registrars. The authorization gateway only reads it once request handling begins;
 * unknown, disabled, retired, unbound, or ambiguously registered capabilities therefore fail closed
 * without a second hard-coded action/resource or system-authority catalog.
 *
 * @since  2.0.0
 */
final readonly class AuthorizationPolicyRegistry
{
    /**
     * Operational capability definitions keyed by capability identifier.
     *
     * @var    CapabilityDefinitionRegistry
     * @since  2.0.0
     */
    private CapabilityDefinitionRegistry $capabilities;

    /**
     * Operational action/resource bindings validated against the capability catalog.
     *
     * @var    ResourcePolicyRegistry
     * @since  2.0.0
     */
    private ResourcePolicyRegistry $resourcePolicies;

    /**
     * Create an empty registry ready for the core and active extension contribution phase.
     *
     * The composition root must share this instance with both the contribution registry set and the
     * gateway. Keeping construction empty prevents core from taking a registration path extensions do not.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        $this->capabilities = new CapabilityDefinitionRegistry();
        $this->resourcePolicies = new ResourcePolicyRegistry($this->capabilities);
    }

    /**
     * Add one owner-bound capability definition.
     *
     * @param   CapabilityDefinition  $definition  Typed metadata contributed by core or an extension.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function registerCapability(CapabilityDefinition $definition): void
    {
        $this->capabilities->register($definition);
    }

    /**
     * Add one owner-bound action/resource policy after its capability has been registered.
     *
     * @param   ResourcePolicyDefinition  $definition  Typed binding contributed by the same capability owner.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function registerResourcePolicy(ResourcePolicyDefinition $definition): void
    {
        $this->resourcePolicies->register($definition);
    }

    /**
     * Reach the capability-definition catalog for persistence and diagnostic adapters.
     *
     * @return  CapabilityDefinitionRegistry  The same live catalog this registry evaluates.
     *
     * @since   2.0.0
     */
    public function capabilityDefinitions(): CapabilityDefinitionRegistry
    {
        return $this->capabilities;
    }

    /**
     * Reach the resource-policy catalog for persistence and diagnostic adapters.
     *
     * @return  ResourcePolicyRegistry  The same live catalog this registry evaluates.
     *
     * @since   2.0.0
     */
    public function resourcePolicies(): ResourcePolicyRegistry
    {
        return $this->resourcePolicies;
    }

    /**
     * Resolve a capability's operational metadata, including owner and lifecycle.
     *
     * @param   Capability  $capability  Permission code being inspected.
     *
     * @return  ?CapabilityDefinition  Registered metadata, or null when no owner currently publishes it.
     *
     * @since   2.0.0
     */
    public function capability(Capability $capability): ?CapabilityDefinition
    {
        return $this->capabilities->definition($capability);
    }

    /**
     * Resolve the typed base policy for one action/resource request.
     *
     * @param   Capability             $action    Capability being exercised.
     * @param   AuthorizationResource  $resource  Resource the action is aimed at.
     *
     * @return  ?ResourcePolicyDefinition  Matching enforceable binding, or null when unsupported.
     *
     * @since   2.0.0
     */
    public function resourcePolicy(
        Capability $action,
        AuthorizationResource $resource,
    ): ?ResourcePolicyDefinition {
        $capability = $this->capabilities->definition($action);
        if ($capability === null || !$capability->enforceable()) {
            return null;
        }

        return $this->resourcePolicies->definitionFor($action, $resource);
    }

    /**
     * Decide whether an action is meaningful against a resource at all.
     *
     * @param   Capability             $action    Capability being exercised.
     * @param   AuthorizationResource  $resource  Resource the action is aimed at.
     *
     * @return  bool  True only when enforceable owner-bound capability and policy definitions match.
     *
     * @since   2.0.0
     */
    public function supports(Capability $action, AuthorizationResource $resource): bool
    {
        return $this->resourcePolicy($action, $resource) !== null;
    }

    /**
     * Decide whether a capability may be granted onward at the proposed scope.
     *
     * Global and site scopes are governed directly by capability metadata. A narrower scope must also
     * name a resource that an enforceable policy binds to the capability, preventing arbitrary scope
     * type strings from being stored merely because the capability lists a similar name.
     *
     * @param   Capability  $action  Capability the caller proposes to grant onward.
     * @param   GrantScope  $scope   Exact reach of the proposed grant.
     *
     * @return  bool  True when metadata permits delegation and the requested scope is meaningful.
     *
     * @since   2.0.0
     */
    public function supportsDelegation(Capability $action, GrantScope $scope): bool
    {
        $definition = $this->capabilities->definition($action);
        if ($definition === null || !$definition->allowsDelegation($scope)) {
            return false;
        }
        if ($scope->isGlobal() || $scope->type() === 'site') {
            return true;
        }

        $identifier = $scope->identifier();
        return $identifier !== null && $this->supports(
            $action,
            AuthorizationResource::item($scope->type(), $identifier),
        );
    }

    /**
     * Decide whether only an installation-wide human grant can authorize this request.
     *
     * @param   Capability             $action    Capability being exercised.
     * @param   AuthorizationResource  $resource  Resource the action is aimed at.
     *
     * @return  bool  The matching policy's explicit installation-global classification.
     *
     * @since   2.0.0
     */
    public function requiresGlobalGrant(Capability $action, AuthorizationResource $resource): bool
    {
        return $this->resourcePolicy($action, $resource)?->installationGlobal ?? false;
    }

    /**
     * Whether a human principal may exercise this registered capability through stored grants.
     *
     * @param   Capability  $action  Capability being evaluated.
     *
     * @return  bool  False for unknown, unenforceable, or system-only definitions.
     *
     * @since   2.0.0
     */
    public function allowsHumanGrant(Capability $action): bool
    {
        $definition = $this->capabilities->definition($action);

        return $definition !== null && $definition->enforceable() && $definition->allowsHumanGrant();
    }

    /**
     * Whether a delegated credential for this capability must carry a live organization membership.
     *
     * The decision follows enforceable typed resource targets, not capability namespaces. An extension
     * capability bound to business records is therefore constrained exactly like a core capability,
     * while a similarly named capability targeting only site resources is not accidentally constrained.
     *
     * @param   Capability  $action  Capability being considered for a delegated credential.
     *
     * @return  bool  True when any live binding reaches organization-sensitive resources.
     *
     * @since   2.0.0
     */
    public function requiresMembershipContext(Capability $action): bool
    {
        $definition = $this->capabilities->definition($action);
        if ($definition === null || !$definition->enforceable()) {
            return false;
        }

        $sensitive = array_fill_keys([
            'approval_request',
            'business_record',
            'organization',
            'organization_membership',
            'resource_policy',
            'separation_duty_rule',
            'workspace',
        ], true);
        foreach ($this->resourcePolicies->definitionsFor($action) as $policy) {
            foreach ($policy->targets as $target) {
                if (isset($sensitive[$target->type])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the matching policy grants an unattended identity this exact action/resource pair.
     *
     * @param   Capability             $action    Capability being exercised.
     * @param   AuthorizationResource  $resource  Resource the action is aimed at.
     * @param   SystemIdentity         $identity  Purpose-built unattended actor.
     *
     * @return  bool  True only when the enforceable resource policy names that identity.
     *
     * @since   2.0.0
     */
    public function allowsSystemIdentity(
        Capability $action,
        AuthorizationResource $resource,
        SystemIdentity $identity,
    ): bool {
        return $this->resourcePolicy($action, $resource)?->allowsSystemIdentity($identity) ?? false;
    }

    /**
     * Withdraw every policy and capability belonging to an owner, policies first.
     *
     * @param   string  $owner  Core or extension owner whose live authority is being removed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function removeOwner(string $owner): void
    {
        $this->resourcePolicies->removeOwner($owner);
        $this->capabilities->removeOwner($owner);
    }
}
