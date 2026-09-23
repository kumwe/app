<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Contribution;

use Kumwe\Access\AuthorizationDefinitionLifecycle;
use Kumwe\Access\AuthorizationPolicyRegistry;
use Kumwe\Access\Capability;
use Kumwe\Access\MembershipRequirement;
use Kumwe\Access\ResourcePolicyDefinition;
use Kumwe\Access\ResourcePolicyTarget;
use Kumwe\App\Extension\Contribution\CapabilityDefinition;
use Kumwe\App\Extension\Contribution\CapabilityDefinitionRegistry;
use Kumwe\Contribution\ContributionOwner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves an isolated capability surface is backed by a private registry carrying the host membership policy.
 *
 * The contribution surface normally mirrors the shared live registry it is given. Built without one, it must
 * not fall back to a registry that knows nothing of which resource types demand membership context, or an
 * isolated use would judge a business-record capability more loosely than the running host does.
 *
 * @since  2.0.0
 */
#[CoversClass(CapabilityDefinitionRegistry::class)]
final class CapabilityDefinitionRegistryTest extends TestCase
{
    /**
     * Without a shared registry the surface builds its own, under the host membership requirement.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnIsolatedRegistryCarriesTheHostMembershipPolicy(): void
    {
        $capability = Capability::fromString('acme.inspection.view');
        $isolated = new CapabilityDefinitionRegistry();
        $explicit = new CapabilityDefinitionRegistry(
            new AuthorizationPolicyRegistry(new MembershipRequirement([])),
        );

        foreach ([$isolated, $explicit] as $registry) {
            $registry->register(ContributionOwner::extension('acme/inspection'), new CapabilityDefinition(
                'acme.inspection.view',
                'View inspections',
                'View policy-filtered inspection records.',
            ));
            $registry->authorizationPolicies()->registerResourcePolicy(new ResourcePolicyDefinition(
                'acme.inspection.view-records',
                'acme/inspection',
                $capability,
                [new ResourcePolicyTarget('business_record')],
                false,
                [],
                AuthorizationDefinitionLifecycle::Active,
                1,
            ));
        }

        self::assertNotSame(
            $isolated->authorizationPolicies(),
            (new CapabilityDefinitionRegistry())->authorizationPolicies(),
            'Each isolated surface owns a private registry.',
        );
        self::assertNotNull($isolated->authorizationPolicies()->capability($capability));
        self::assertTrue(
            $isolated->authorizationPolicies()->requiresMembershipContext($capability),
            'A business record is a host membership resource type, so the private registry must know it.',
        );
        self::assertFalse(
            $explicit->authorizationPolicies()->requiresMembershipContext($capability),
            'A registry handed in is used as given, so an empty requirement stays empty.',
        );
    }
}
