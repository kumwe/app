<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Identity\Application\Authentication;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Application\Authentication\PrincipalGrant;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\GrantScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Kumwe\CMS\Tests\Support\AuthorizationContext;

#[CoversClass(AuthenticatedPrincipal::class)]
#[UsesClass(Capability::class)]
#[UsesClass(GrantScope::class)]
#[UsesClass(PrincipalGrant::class)]
final class AuthenticatedPrincipalTest extends TestCase
{
    private const SUBJECT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    public function testStoresValidatedSubjectAndSortedExactCapabilities(): void
    {
        $principal = AuthorizationContext::principal([
            'content.update',
            'content.read',
        ], self::SUBJECT);

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

        AuthorizationContext::principal(['content.*'], self::SUBJECT);
    }

    public function testKeepsScopedGrantsInsteadOfPromotingThemToGlobal(): void
    {
        $principal = AuthorizationContext::principalFromGrantRows([[
            'capability' => 'content.update',
            'scope_type' => 'content',
            'scope_identifier' => 'page-1',
        ]], self::SUBJECT);
        $capability = Capability::fromString('content.update');

        self::assertTrue($principal->hasCapability($capability));
        self::assertTrue($principal->allows($capability, [GrantScope::named('content', 'page-1')]));
        self::assertFalse($principal->allows($capability, [GrantScope::named('content', 'page-2')]));
    }

    public function testRejectsDuplicateCapability(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthorizationContext::principal(['content.read', 'content.read'], self::SUBJECT);
    }

    public function testRejectsNonUuidSubject(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthorizationContext::principal(['content.read'], 'user-1');
    }
}
