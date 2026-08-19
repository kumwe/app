<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Infrastructure\Security;

use InvalidArgumentException;
use Kumwe\App\Identity\Infrastructure\Security\NativePasswordHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NativePasswordHasher::class)]
final class NativePasswordHasherTest extends TestCase
{
    public function testHashesVerifiesAndRejectsAnIncorrectPassword(): void
    {
        $hasher = new NativePasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]);
        $hash = $hasher->hash('correct horse battery staple');

        self::assertNotSame('correct horse battery staple', $hash);
        self::assertTrue($hasher->verify('correct horse battery staple', $hash));
        self::assertFalse($hasher->verify('incorrect', $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }

    public function testDetectsHashesUsingDifferentOptions(): void
    {
        $oldHash = (new NativePasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]))->hash('password');

        self::assertTrue((new NativePasswordHasher(PASSWORD_BCRYPT, ['cost' => 5]))->needsRehash($oldHash));
    }

    public function testRejectsAnEmptyPassword(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NativePasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]))->hash('');
    }

    public function testRejectsPasswordsThatBcryptWouldSilentlyTruncate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new NativePasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]))->hash(str_repeat('a', 73));
    }
}
