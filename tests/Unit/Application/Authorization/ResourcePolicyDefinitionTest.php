<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Authorization;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDefinitionLifecycle;
use Kumwe\App\Application\Authorization\ResourcePolicyDefinition;
use Kumwe\App\Application\Authorization\ResourcePolicyTarget;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves a resource-policy definition keeps unattended system authority a core-only, typed allowlist.
 *
 * @since  2.0.0
 */
#[CoversClass(ResourcePolicyDefinition::class)]
final class ResourcePolicyDefinitionTest extends TestCase
{
    /**
     * An extension-owned policy is refused at construction when it names any system identity.
     *
     * Unattended authority is registered as core data only; an installed package cannot widen what a
     * worker or scheduler may do by declaring its own binding, however narrow its targets are.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExtensionPolicyCannotGrantAuthorityToSystemIdentities(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Extension resource policies cannot grant authority to system identities.');

        new ResourcePolicyDefinition(
            'acme.editor.system',
            'acme/editor',
            Capability::fromString('acme.editor.manage'),
            [new ResourcePolicyTarget('acme_editor_record')],
            false,
            [SystemIdentity::Worker],
            AuthorizationDefinitionLifecycle::Active,
            1,
        );
    }

    /**
     * A core policy answers only for the identities it names, and exports them sorted and de-duplicated.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACorePolicyAllowsExactlyTheSystemIdentitiesItNames(): void
    {
        $policy = new ResourcePolicyDefinition(
            'core.content.read',
            'core',
            Capability::fromString('content.read'),
            [new ResourcePolicyTarget('content')],
            false,
            [SystemIdentity::Worker, SystemIdentity::ProfileInstaller, SystemIdentity::Worker],
            AuthorizationDefinitionLifecycle::Active,
            1,
        );

        self::assertTrue($policy->allowsSystemIdentity(SystemIdentity::Worker));
        self::assertTrue($policy->allowsSystemIdentity(SystemIdentity::ProfileInstaller));
        self::assertFalse($policy->allowsSystemIdentity(SystemIdentity::Scheduler));
        self::assertFalse($policy->allowsSystemIdentity(SystemIdentity::Migration));
        self::assertSame(
            ['system:profile-installer', 'system:worker'],
            $policy->toArray()['system_identities'],
        );
    }

    /**
     * An extension policy with no system identities is accepted and grants none of them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExtensionPolicyWithoutSystemIdentitiesGrantsNoUnattendedAuthority(): void
    {
        $policy = new ResourcePolicyDefinition(
            'acme.editor.records',
            'acme/editor',
            Capability::fromString('acme.editor.manage'),
            [new ResourcePolicyTarget('acme_editor_record')],
            false,
            [],
            AuthorizationDefinitionLifecycle::Active,
            1,
        );

        self::assertSame([], $policy->systemIdentities);
        foreach (SystemIdentity::cases() as $identity) {
            self::assertFalse($policy->allowsSystemIdentity($identity), $identity->value);
        }
    }
}
