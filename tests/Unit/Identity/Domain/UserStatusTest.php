<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Domain;

use Kumwe\App\Identity\Domain\UserStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserStatus::class)]
final class UserStatusTest extends TestCase
{
    public function testOnlyActiveUsersCanAuthenticate(): void
    {
        self::assertTrue(UserStatus::Active->canAuthenticate());
        self::assertFalse(UserStatus::Pending->canAuthenticate());
        self::assertFalse(UserStatus::Suspended->canAuthenticate());
        self::assertFalse(UserStatus::Disabled->canAuthenticate());
    }
}
