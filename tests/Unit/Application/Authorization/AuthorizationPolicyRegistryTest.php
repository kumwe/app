<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Authorization;

use Kumwe\App\Application\Authorization\AuthorizationDefinitionLifecycle;
use Kumwe\App\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\App\Application\Authorization\CapabilityDefinition;
use Kumwe\App\Application\Authorization\ResourcePolicyDefinition;
use Kumwe\App\Application\Authorization\ResourcePolicyTarget;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthorizationPolicyRegistry::class)]
final class AuthorizationPolicyRegistryTest extends TestCase
{
    public function testExtensionBusinessRecordTargetRequiresMembershipWithoutNamespaceConvention(): void
    {
        $registry = new AuthorizationPolicyRegistry();
        $capability = Capability::fromString('acme.invoice.inspect');
        $registry->registerCapability(new CapabilityDefinition(
            $capability,
            'acme/invoice',
            ['business_record'],
            true,
            false,
            AuthorizationDefinitionLifecycle::Active,
            1,
        ));
        $registry->registerResourcePolicy(new ResourcePolicyDefinition(
            'acme.invoice.record-policy',
            'acme/invoice',
            $capability,
            [new ResourcePolicyTarget('business_record')],
            false,
            [],
            AuthorizationDefinitionLifecycle::Active,
            1,
        ));

        self::assertTrue($registry->requiresMembershipContext($capability));
    }

    public function testCapabilityNameDoesNotImplyMembershipWithoutSensitiveTarget(): void
    {
        $registry = new AuthorizationPolicyRegistry();
        $capability = Capability::fromString('acme.invoice.business-record-report');
        $registry->registerCapability(new CapabilityDefinition(
            $capability,
            'acme/invoice',
            ['site'],
            true,
            false,
            AuthorizationDefinitionLifecycle::Active,
            1,
        ));
        $registry->registerResourcePolicy(new ResourcePolicyDefinition(
            'acme.invoice.site-policy',
            'acme/invoice',
            $capability,
            [new ResourcePolicyTarget('site')],
            false,
            [],
            AuthorizationDefinitionLifecycle::Active,
            1,
        ));

        self::assertFalse($registry->requiresMembershipContext($capability));
    }

    /**
     * Prove a retained disabled resource policy cannot authorize or impose credential scope.
     *
     * @since  2.0.0
     */
    public function testDisabledResourcePolicyFailsClosed(): void
    {
        $registry = new AuthorizationPolicyRegistry();
        $capability = Capability::fromString('acme.invoice.inspect');
        $registry->registerCapability(new CapabilityDefinition(
            $capability,
            'acme/invoice',
            ['business_record'],
            true,
            false,
            AuthorizationDefinitionLifecycle::Active,
            1,
        ));
        $registry->registerResourcePolicy(new ResourcePolicyDefinition(
            'acme.invoice.retired-record-policy',
            'acme/invoice',
            $capability,
            [new ResourcePolicyTarget('business_record')],
            false,
            [],
            AuthorizationDefinitionLifecycle::Disabled,
            2,
        ));

        self::assertFalse($registry->supports(
            $capability,
            \Kumwe\App\Application\Authorization\AuthorizationResource::collection('business_record'),
        ));
        self::assertFalse($registry->requiresMembershipContext($capability));
    }
}
