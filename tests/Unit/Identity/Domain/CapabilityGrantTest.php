<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Identity\Domain;

use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\CapabilityGrant;
use Kumwe\CMS\Identity\Domain\EmailAddress;
use Kumwe\CMS\Identity\Domain\GrantScope;
use Kumwe\CMS\Identity\Domain\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CapabilityGrant::class)]
final class CapabilityGrantTest extends TestCase
{
    public function testAppliesOnlyWhenRoleCapabilityAndScopeMatch(): void
    {
        $user = User::register(
            '4f52fd0a-7296-4c4a-8e5d-85bc600f9718',
            EmailAddress::fromString('editor@example.com'),
            'Editor',
        );
        $user->assignRole('content.editor');
        $grant = new CapabilityGrant(
            'content.editor',
            Capability::fromString('content.publish'),
            GrantScope::named('site', 'primary'),
        );

        self::assertTrue($grant->appliesTo(
            $user,
            Capability::fromString('content.publish'),
            GrantScope::named('site', 'primary'),
        ));
        self::assertFalse($grant->appliesTo(
            $user,
            Capability::fromString('content.delete'),
            GrantScope::named('site', 'primary'),
        ));
    }
}
