<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Identity\Application\Administration;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationDenied;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Identity\Application\Administration\AccessControlRepository;
use Kumwe\CMS\Identity\Application\Administration\AccessControlService;
use Kumwe\CMS\Identity\Application\Security\PasswordHasher;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\EmailAddress;
use Kumwe\CMS\Identity\Domain\UserStatus;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(AccessControlService::class)]
#[UsesClass(AuditEvent::class)]
#[UsesClass(Capability::class)]
#[UsesClass(EmailAddress::class)]
final class AccessControlServiceTest extends TestCase
{
    private const ACTOR = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
    private const USER = '018f22e2-7c8b-7ab0-8f3a-88e8026bb302';
    private const ROLE = '018f22e2-7c8b-7ab0-8f3a-88e8026bb303';

    public function testCreatesNormalizedUserWithHashedPasswordAndAuditEvent(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('insertUser')->with(
            self::isType('string'),
            'editor@example.test',
            'Site Editor',
            'active',
            'verified-password-hash',
            self::equalTo(new DateTimeImmutable('2026-08-04T10:00:00+00:00')),
        );
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->expects(self::once())->method('hash')
            ->with('correct horse battery staple')
            ->willReturn('verified-password-hash');
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->actorId() === self::ACTOR
                && $event->action() === 'user.create'
                && $event->metadata() === ['status' => 'active'],
        ));

        $id = $this->service($repository, $passwords, $audit)->createUser(
            $this->context(),
            ' Editor@Example.Test ',
            ' Site Editor ',
            'correct horse battery staple',
        );

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            $id,
        );
    }

    public function testPreventsAdministratorFromDisablingOwnAccount(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('updateUser');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('your own administrator account');

        $this->service($repository)->updateUser(
            $this->context(),
            self::ACTOR,
            'administrator@example.test',
            'Administrator',
            UserStatus::Disabled,
            2,
        );
    }

    public function testAppliesAPermittedLifecycleTransitionUnderTheUserLock(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->method('userStatus')->with(self::USER)->willReturn('active');
        $repository->expects(self::once())->method('lockUser')->with(self::USER);
        $repository->expects(self::once())->method('updateUser')->with(
            self::USER,
            'member@example.test',
            'Member',
            'suspended',
            3,
            self::equalTo(new DateTimeImmutable('2026-08-04T10:00:00+00:00')),
        );

        $this->service($repository)->updateUser(
            $this->context(),
            self::USER,
            'member@example.test',
            'Member',
            UserStatus::Suspended,
            3,
        );
    }

    public function testRefusesToReactivateADisabledUser(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->method('userStatus')->with(self::USER)->willReturn('disabled');
        $repository->expects(self::never())->method('updateUser');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A disabled user cannot become active.');

        $this->service($repository)->updateUser(
            $this->context(),
            self::USER,
            'member@example.test',
            'Member',
            UserStatus::Active,
            3,
        );
    }

    public function testRefusesToSuspendAUserThatIsNotActive(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->method('userStatus')->with(self::USER)->willReturn('pending');
        $repository->expects(self::never())->method('updateUser');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A pending user cannot become suspended.');

        $this->service($repository)->updateUser(
            $this->context(),
            self::USER,
            'member@example.test',
            'Member',
            UserStatus::Suspended,
            3,
        );
    }

    public function testRefusesToUpdateAUserThatNoLongerExists(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->method('userStatus')->with(self::USER)->willReturn(null);
        $repository->expects(self::never())->method('updateUser');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The user does not exist.');

        $this->service($repository)->updateUser(
            $this->context(),
            self::USER,
            'member@example.test',
            'Member',
            UserStatus::Active,
            3,
        );
    }

    public function testCreatesScopedCapabilityGrantInsideAuditBoundary(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('grant')->with(
            self::isType('string'),
            self::ROLE,
            'content.update',
            'content',
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb309',
            self::ACTOR,
            self::equalTo(new DateTimeImmutable('2026-08-04T10:00:00+00:00')),
        );
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'capability.grant'
                && $event->metadata()['scope_identifier'] === '018f22e2-7c8b-7ab0-8f3a-88e8026bb309',
        ));

        $grantId = $this->service($repository, audit: $audit)->grant(
            $this->context(['users.manage', 'content.update']),
            self::ROLE,
            ' Content.Update ',
            'content',
            ' 018f22e2-7c8b-7ab0-8f3a-88e8026bb309 ',
        );

        self::assertNotSame('', $grantId);
    }

    public function testCannotAssignRoleWhoseGrantsExceedActorsEffectiveAuthority(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('roleGrants')->with(self::ROLE)->willReturn([[
            'capability' => 'content.publish',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ]]);
        $repository->expects(self::never())->method('assignRole');

        $this->expectException(AuthorizationDenied::class);

        $this->service($repository)->assignRole($this->context(), self::USER, self::ROLE);
    }

    public function testCannotGrantCapabilityBroaderThanActorsEffectiveAuthority(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('grant');

        $this->expectException(AuthorizationDenied::class);

        $this->service($repository)->grant(
            AuthorizationContext::principalFromGrantRows([
                ['capability' => 'users.manage', 'scope_type' => 'global', 'scope_identifier' => null],
                [
                    'capability' => 'content.update',
                    'scope_type' => 'content',
                    'scope_identifier' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb309',
                ],
            ], self::ACTOR)->context(
                \Kumwe\CMS\Application\Authorization\SiteContext::default(),
                \Kumwe\CMS\Application\Authorization\AuthenticationStrength::BearerToken,
                'delegation-ceiling-test',
            ),
            self::ROLE,
            'content.update',
            'global',
        );
    }

    public function testSiteScopedUsersManagerCannotSelfEscalateThroughGlobalRoleGrant(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('grant');
        $context = AuthorizationContext::principalFromGrantRows([[
            'capability' => 'users.manage',
            'scope_type' => 'site',
            'scope_identifier' => 'default',
        ]], self::ACTOR)->context(
            \Kumwe\CMS\Application\Authorization\SiteContext::default(),
            \Kumwe\CMS\Application\Authorization\AuthenticationStrength::BearerToken,
            'site-identity-escalation-test',
        );

        $this->expectException(AuthorizationDenied::class);
        $this->service($repository)->grant(
            $context,
            self::ROLE,
            'users.manage',
            'global',
        );
    }

    public function testRejectsMalformedGlobalGrantBeforePersistence(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('grant');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Global grants cannot have a scope identifier');

        $this->service($repository)->grant(
            $this->context(),
            self::ROLE,
            'content.update',
            'global',
            'news',
        );
    }

    public function testPreventsAdministratorFromRevokingOwnAdministratorRole(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('roleCode')->with(self::ROLE)->willReturn('administrator');
        $repository->expects(self::never())->method('revokeRole');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('own administrator role');

        $this->service($repository)->revokeRole($this->context(), self::ACTOR, self::ROLE);
    }

    public function testSiteScopedManagerCannotReadInstallationWideIdentityInventory(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('users');

        $this->expectException(AuthorizationDenied::class);
        $this->service($repository)->users(AuthorizationContext::siteScoped('users.manage'));
    }

    public function testManagerCannotMintAnInstallationWideGrantItDoesNotHold(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('grant');

        $this->expectException(AuthorizationDenied::class);
        $this->service($repository)->grant(
            AuthorizationContext::human(['users.manage'], self::ACTOR),
            self::ROLE,
            'extensions.manage',
        );
    }

    public function testManagerCannotAssignARoleContainingCapabilitiesItCannotDelegate(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->method('roleGrants')->with(self::ROLE)->willReturn([[
            'capability' => 'extensions.manage',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ]]);
        $repository->expects(self::never())->method('assignRole');

        $this->expectException(AuthorizationDenied::class);
        $this->service($repository)->assignRole(
            AuthorizationContext::human(['users.manage'], self::ACTOR),
            self::USER,
            self::ROLE,
        );
    }

    public function testRevokesTokenInsideAuditedTransaction(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('tokenSite')->willReturn('default');
        $repository->expects(self::once())->method('revokeToken')->with(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb305',
            self::equalTo(new DateTimeImmutable('2026-08-04T10:00:00+00:00')),
        );
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'token.revoke'
                && $event->actorId() === self::ACTOR,
        ));

        $this->service($repository, audit: $audit)->revokeToken(
            $this->context(),
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb305',
        );
    }

    public function testRevokingGrantAlsoRemovesItsInstallationOwnership(): void
    {
        $grantId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb306';
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('grantRecord')->with($grantId)->willReturn([
            'role_id' => self::ROLE,
            'capability' => 'content.update',
            'scope_type' => 'global',
            'scope_identifier' => null,
        ]);
        $repository->expects(self::once())->method('lockRole')->with(self::ROLE);
        $repository->expects(self::once())->method('revokeGrant')->with($grantId);
        $ownership = $this->createMock(ResourceSiteOwnershipWriter::class);
        $ownership->expects(self::once())->method('remove')->with(
            self::callback(static fn (AuthorizationResource $resource): bool => $resource->type() === 'grant'
                && $resource->identifier() === $grantId),
            self::callback(static fn (SiteContext $site): bool => $site->identifier() === SiteContext::DEFAULT),
        );

        $this->service(
            $repository,
            audit: $this->createStub(AuditRecorder::class),
            ownership: $ownership,
        )->revokeGrant($this->context(), $grantId);
    }

    public function testSiteScopedGrantCannotReadInstallationGlobalUsers(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('users');
        $context = AuthorizationContext::principalFromGrantRows([[
            'capability' => 'users.manage',
            'scope_type' => 'site',
            'scope_identifier' => 'default',
        ]], self::ACTOR)->context(
            \Kumwe\CMS\Application\Authorization\SiteContext::default(),
            \Kumwe\CMS\Application\Authorization\AuthenticationStrength::BearerToken,
            'access-page-test',
        );

        $this->expectException(AuthorizationDenied::class);
        $this->service($repository)->users($context);
    }

    public function testSiteTokenRevocationLocksTheSameUserRowUsedByIssuance(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $order = 0;
        $repository->expects(self::once())
            ->method('lockUser')
            ->with(self::USER)
            ->willReturnCallback(static function () use (&$order): void {
                self::assertSame(0, $order++);
            });
        $repository->expects(self::once())
            ->method('revokeSubjectTokensForSite')
            ->with(
                self::USER,
                'default',
                self::equalTo(new DateTimeImmutable('2026-08-04T10:00:00+00:00')),
                'site compromise',
            )
            ->willReturnCallback(static function () use (&$order): int {
                self::assertSame(1, $order++);
                return 2;
            });

        self::assertSame(2, $this->service($repository)->revokeSubjectTokens(
            AuthorizationContext::siteScoped('users.manage'),
            self::USER,
            'site compromise',
        ));
    }

    private function service(
        AccessControlRepository $repository,
        ?PasswordHasher $passwords = null,
        ?AuditRecorder $audit = null,
        ?ResourceSiteOwnershipWriter $ownership = null,
    ): AccessControlService {
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-04T10:00:00+00:00'));

        return new AccessControlService(
            $repository,
            $passwords ?? $this->createStub(PasswordHasher::class),
            $transactions,
            $audit ?? $this->createStub(AuditRecorder::class),
            $clock,
            AuthorizationContext::gateway(),
            $ownership ?? AuthorizationContext::ownershipWriter(),
        );
    }

    /** @param list<string> $capabilities */
    private function context(array $capabilities = ['users.manage']): ExecutionContext
    {
        return AuthorizationContext::human($capabilities, self::ACTOR);
    }
}
