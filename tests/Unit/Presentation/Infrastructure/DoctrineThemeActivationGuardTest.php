<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Infrastructure;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\CMS\Identity\Application\Security\PasswordHasher;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Presentation\Application\StepUpAuthenticationRequired;
use Kumwe\CMS\Presentation\Infrastructure\DoctrineThemeActivationGuard;
use Kumwe\CMS\Extension\Domain\ThemeSurface;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineThemeActivationGuard::class)]
#[CoversClass(StepUpAuthenticationRequired::class)]
final class DoctrineThemeActivationGuardTest extends TestCase
{
    private const ACTOR = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    public function testSiteActivationDoesNotRequestStepUpCredential(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('fetchOne');
        $guard = new DoctrineThemeActivationGuard(
            $database,
            new TableNames($database, 'kumwe_'),
            $this->createStub(PasswordHasher::class),
            $this->createStub(AuthenticationRateLimiter::class),
        );

        $guard->assertAllowed(
            ThemeSurface::Site,
            AuthorizationContext::human(['themes.site.manage'], self::ACTOR),
            null,
        );
        self::addToAssertionCount(1);
    }

    public function testAdministratorActivationAcceptsOnlyTheCurrentPassword(): void
    {
        $database = $this->createStub(Connection::class);
        $database->method('fetchOne')->willReturn('stored-password-hash');
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->expects(self::once())->method('verify')->with(
            'current password',
            'stored-password-hash',
        )->willReturn(true);
        $rateLimiter = $this->createMock(AuthenticationRateLimiter::class);
        $rateLimiter->expects(self::once())->method('assertAllowed');
        $rateLimiter->expects(self::once())->method('record')->with(
            self::isString(),
            self::isString(),
            true,
        );
        $guard = new DoctrineThemeActivationGuard(
            $database,
            new TableNames($database, 'kumwe_'),
            $passwords,
            $rateLimiter,
        );

        $guard->assertAllowed(
            ThemeSurface::Administrator,
            AuthorizationContext::human(['themes.administrator.manage'], self::ACTOR),
            'current password',
        );
        self::addToAssertionCount(1);
    }

    public function testAdministratorActivationFailsClosedWithoutValidStepUp(): void
    {
        $database = $this->createStub(Connection::class);
        $database->method('fetchOne')->willReturn('stored-password-hash');
        $passwords = $this->createStub(PasswordHasher::class);
        $passwords->method('verify')->willReturn(false);
        $guard = new DoctrineThemeActivationGuard(
            $database,
            new TableNames($database, 'kumwe_'),
            $passwords,
            $this->createStub(AuthenticationRateLimiter::class),
        );
        $this->expectException(StepUpAuthenticationRequired::class);

        $guard->assertAllowed(
            ThemeSurface::Administrator,
            AuthorizationContext::human(['themes.administrator.manage'], self::ACTOR),
            'wrong password',
        );
    }
}
