<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Identity\Application\Authentication;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Domain\Capability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthenticatedPrincipal::class)]
#[UsesClass(Capability::class)]
final class AuthenticatedPrincipalTest extends TestCase
{
    private const SUBJECT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    public function testStoresValidatedSubjectAndSortedExactCapabilities(): void
    {
        $principal = AuthenticatedPrincipal::fromStrings(self::SUBJECT, [
            'content.update',
            'content.read',
        ]);

        self::assertSame(self::SUBJECT, $principal->subject());
        self::assertSame(
            ['content.read', 'content.update'],
            array_map(
                static fn (Capability $capability): string => $capability->value(),
                $principal->capabilities(),
            ),
        );
        self::assertTrue($principal->hasCapability(Capability::fromString('content.read')));
        self::assertFalse($principal->hasCapability(Capability::fromString('content')));
    }

    public function testRejectsWildcardCapability(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthenticatedPrincipal::fromStrings(self::SUBJECT, ['content.*']);
    }

    public function testRejectsDuplicateCapability(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthenticatedPrincipal::fromStrings(self::SUBJECT, ['content.read', 'content.read']);
    }

    public function testRejectsNonUuidSubject(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthenticatedPrincipal::fromStrings('user-1', ['content.read']);
    }
}
