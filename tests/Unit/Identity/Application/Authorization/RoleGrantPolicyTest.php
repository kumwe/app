<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Application\Authorization;

use Kumwe\App\Identity\Application\Authorization\RoleGrantPolicy;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use Kumwe\App\Identity\Domain\CapabilityGrant;
use Kumwe\App\Identity\Domain\EmailAddress;
use Kumwe\App\Identity\Domain\GrantScope;
use Kumwe\App\Identity\Domain\User;
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
