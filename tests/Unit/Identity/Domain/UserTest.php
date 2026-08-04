<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Identity\Domain;

use DomainException;
use InvalidArgumentException;
use Kumwe\CMS\Identity\Domain\EmailAddress;
use Kumwe\CMS\Identity\Domain\User;
use Kumwe\CMS\Identity\Domain\UserStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    private const USER_ID = '4f52fd0a-7296-4c4a-8e5d-85bc600f9718';

    public function testRegistrationStartsPendingWithoutImplicitRoles(): void
    {
        $user = $this->registeredUser();

        self::assertSame(UserStatus::Pending, $user->status());
        self::assertSame([], $user->roles());
        self::assertSame(0, $user->version());
        self::assertFalse($user->canAuthenticate());
    }

    public function testMutationsIncrementTheOptimisticVersionOnlyOnChange(): void
    {
        $user = $this->registeredUser();
        $user->activate();
        $user->activate();
        $user->assignRole('content.editor');
        $user->assignRole('content.editor');
        $user->rename('Updated Name');
        $user->changeEmail(EmailAddress::fromString('updated@example.com'));

        self::assertSame(4, $user->version());
        self::assertSame(['content.editor'], $user->roles());
        self::assertSame('Updated Name', $user->displayName());
        self::assertTrue($user->canAuthenticate());
    }

    public function testRoleRevocationIsExplicitAndVersioned(): void
    {
        $user = User::reconstitute(
            self::USER_ID,
            EmailAddress::fromString('user@example.com'),
            'User',
            ['site.admin', 'content.editor', 'site.admin'],
            UserStatus::Active,
            9,
        );

        self::assertSame(['content.editor', 'site.admin'], $user->roles());
        $user->revokeRole('site.admin');
        $user->revokeRole('site.admin');

        self::assertSame(['content.editor'], $user->roles());
        self::assertSame(10, $user->version());
    }

    public function testDisabledStatusIsTerminal(): void
    {
        $user = $this->registeredUser();
        $user->disable();

        $this->expectException(DomainException::class);
        $user->activate();
    }

    public function testRejectsNonUuidUserIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        User::register('first-user', EmailAddress::fromString('user@example.com'), 'User');
    }

    private function registeredUser(): User
    {
        return User::register(
            self::USER_ID,
            EmailAddress::fromString('user@example.com'),
            'User',
        );
    }
}
