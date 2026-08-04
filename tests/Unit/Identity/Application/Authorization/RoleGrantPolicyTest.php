<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Identity\Application\Authorization;

use Kumwe\CMS\Identity\Application\Authorization\RoleGrantPolicy;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\CapabilityGrant;
use Kumwe\CMS\Identity\Domain\EmailAddress;
use Kumwe\CMS\Identity\Domain\GrantScope;
use Kumwe\CMS\Identity\Domain\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoleGrantPolicy::class)]
final class RoleGrantPolicyTest extends TestCase
{
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

        self::assertTrue($policy->decide($user, $capability, $scope, [$grant])?->isAllowed());
        self::assertNull($policy->decide(
            $user,
            Capability::fromString('content.delete'),
            $scope,
            [$grant],
        ));
    }
}
