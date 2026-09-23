<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Authorization;

use Kumwe\Access\AuthorizationDefinitionLifecycle;
use Kumwe\Access\AuthorizationPolicyRegistry;
use Kumwe\Access\AuthorizationResource;
use Kumwe\Access\Capability;
use Kumwe\Access\CapabilityDefinition;
use Kumwe\Access\ResourcePolicyDefinition;
use Kumwe\Access\ResourcePolicyTarget;
use Kumwe\Access\ResourcePolicyTarget as Target;
use Kumwe\App\Application\Authorization\HostAccessPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the membership-sensitive resource types this build hands the package policy registry.
 *
 * The generic registry behaviour is owned by kumwe/access-control; what stays here is the exact host
 * table: which typed targets make a delegated credential require a live organization membership, and
 * that the answer follows targets rather than capability names.
 *
 * @since  2.0.0
 */
#[CoversClass(HostAccessPolicy::class)]
final class AuthorizationPolicyRegistryTest extends TestCase
{
    /**
     * The host table names exactly the seven organization-sensitive resource types.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheHostMembershipTableNamesExactlyTheSevenSensitiveTypes(): void
    {
        $expected = [
            'approval_request',
            'business_record',
            'organization',
            'organization_membership',
            'resource_policy',
            'separation_duty_rule',
            'workspace',
        ];

        self::assertSame($expected, HostAccessPolicy::membershipResourceTypes());
        self::assertSame($expected, HostAccessPolicy::membershipRequirement()->resourceTypes);
    }

    /**
     * Every host-sensitive target makes its capability membership-sensitive, even for an extension owner.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryHostSensitiveTargetRequiresMembershipWithoutNamespaceConvention(): void
    {
        foreach (HostAccessPolicy::membershipResourceTypes() as $type) {
            $registry = self::registry();
            $capability = Capability::fromString('acme.invoice.inspect');
            $registry->registerCapability(self::capability($capability, [$type]));
            $registry->registerResourcePolicy(self::policy(
                'acme.invoice.record-policy',
                $capability,
                new Target($type),
                AuthorizationDefinitionLifecycle::Active,
            ));

            self::assertTrue($registry->requiresMembershipContext($capability), $type);
        }
    }

    /**
     * A capability whose name mentions a sensitive type is not constrained while its targets are not sensitive.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCapabilityNameDoesNotImplyMembershipWithoutSensitiveTarget(): void
    {
        $registry = self::registry();
        $capability = Capability::fromString('acme.invoice.business-record-report');
        $registry->registerCapability(self::capability($capability, ['site']));
        $registry->registerResourcePolicy(self::policy(
            'acme.invoice.site-policy',
            $capability,
            new Target('site'),
            AuthorizationDefinitionLifecycle::Active,
        ));

        self::assertFalse($registry->requiresMembershipContext($capability));
    }

    /**
     * Prove a retained disabled resource policy cannot authorize or impose credential scope.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDisabledResourcePolicyFailsClosed(): void
    {
        $registry = self::registry();
        $capability = Capability::fromString('acme.invoice.inspect');
        $registry->registerCapability(self::capability($capability, ['business_record']));
        $registry->registerResourcePolicy(self::policy(
            'acme.invoice.retired-record-policy',
            $capability,
            new Target('business_record'),
            AuthorizationDefinitionLifecycle::Disabled,
        ));

        self::assertFalse($registry->supports($capability, AuthorizationResource::collection('business_record')));
        self::assertFalse($registry->requiresMembershipContext($capability));
    }

    /**
     * Build a registry carrying the host membership table, exactly as the composition root configures it.
     *
     * @return  AuthorizationPolicyRegistry  An empty registry with the seven sensitive types.
     *
     * @since   2.0.0
     */
    private static function registry(): AuthorizationPolicyRegistry
    {
        return new AuthorizationPolicyRegistry(HostAccessPolicy::membershipRequirement());
    }

    /**
     * Build an active, delegatable extension capability definition over the given scopes.
     *
     * @param   Capability    $capability  Capability being defined.
     * @param   list<string>  $scopes      Grant-scope types the capability admits.
     *
     * @return  CapabilityDefinition  The definition owned by `acme/invoice`.
     *
     * @since   2.0.0
     */
    private static function capability(Capability $capability, array $scopes): CapabilityDefinition
    {
        return new CapabilityDefinition(
            $capability,
            'acme/invoice',
            $scopes,
            true,
            false,
            AuthorizationDefinitionLifecycle::Active,
            1,
        );
    }

    /**
     * Build an `acme/invoice` resource policy binding one capability to one target.
     *
     * @param   string                            $id          Policy identifier under the owner namespace.
     * @param   Capability                        $capability  Capability the policy binds.
     * @param   ResourcePolicyTarget              $target      The single resource selector.
     * @param   AuthorizationDefinitionLifecycle  $lifecycle   Enforceability state of the binding.
     *
     * @return  ResourcePolicyDefinition  The definition, at version one.
     *
     * @since   2.0.0
     */
    private static function policy(
        string $id,
        Capability $capability,
        ResourcePolicyTarget $target,
        AuthorizationDefinitionLifecycle $lifecycle,
    ): ResourcePolicyDefinition {
        return new ResourcePolicyDefinition($id, 'acme/invoice', $capability, [$target], false, [], $lifecycle, 1);
    }
}
