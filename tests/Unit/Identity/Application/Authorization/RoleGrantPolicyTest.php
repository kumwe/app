<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Application\Authorization;

use Kumwe\Access\Capability;
use Kumwe\Access\DecisionState;
use Kumwe\Access\GrantScope;
use Kumwe\App\Identity\Application\Authorization\RoleGrantPolicy;
use Kumwe\App\Identity\Domain\CapabilityGrant;
use Kumwe\App\Identity\Domain\EmailAddress;
use Kumwe\App\Identity\Domain\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins that role-derived grants become an allowance and that an unmatched request is left to the service.
 *
 * @since  2.0.0
 */
#[CoversClass(RoleGrantPolicy::class)]
final class RoleGrantPolicyTest extends TestCase
{
    /**
     * A covering grant yields an `allow` reasoned `role.grant`; a capability no grant reaches yields an abstention.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAllowsAMatchingRoleGrantAndAbstainsOtherwise(): void
    {
        $user = User::register(
            '4f52fd0a-7296-4c4a-8e5d-85bc600f9718',
            EmailAddress::fromString('editor@example.com'),
            'Editor',
        );
        $user->activate();
        $user->assignRole('content.editor');
        $capability = Capability::fromString('content.publish');
        $scope = GrantScope::named('site', 'primary');
        $grant = new CapabilityGrant('content.editor', $capability, $scope);
        $policy = new RoleGrantPolicy();

        $decision = $policy->decide($user, $capability, $scope, [$grant]);

        self::assertNotNull($decision);
        self::assertTrue($decision->allowed);
        self::assertSame(DecisionState::Allow, $decision->state);
        self::assertSame('core.role-grant.v1', $decision->policy);
        self::assertSame('role.grant', $decision->reason);
        self::assertNull($policy->decide(
            $user,
            Capability::fromString('content.delete'),
            $scope,
            [$grant],
        ));
    }
}
