<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Identity\Application\Administration;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Identity\Application\Administration\AccessControlRepository;
use Kumwe\CMS\Identity\Application\Administration\AccessControlService;
use Kumwe\CMS\Identity\Application\Security\PasswordHasher;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\EmailAddress;
use Kumwe\CMS\Identity\Domain\UserStatus;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
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
            self::ACTOR,
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
            self::ACTOR,
            self::ACTOR,
            'administrator@example.test',
            'Administrator',
            UserStatus::Disabled,
            2,
        );
    }

    public function testCreatesScopedCapabilityGrantInsideAuditBoundary(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::once())->method('grant')->with(
            self::isType('string'),
            self::ROLE,
            'content.update',
            'content_type',
            'news',
            self::ACTOR,
            self::equalTo(new DateTimeImmutable('2026-08-04T10:00:00+00:00')),
        );
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (AuditEvent $event): bool => $event->action() === 'capability.grant'
                && $event->metadata()['scope_identifier'] === 'news',
        ));

        $grantId = $this->service($repository, audit: $audit)->grant(
            self::ACTOR,
            self::ROLE,
            ' Content.Update ',
            'content_type',
            ' news ',
        );

        self::assertNotSame('', $grantId);
    }

    public function testRejectsMalformedGlobalGrantBeforePersistence(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('grant');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Global grants cannot have a scope identifier');

        $this->service($repository)->grant(
            self::ACTOR,
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

        $this->service($repository)->revokeRole(self::ACTOR, self::ACTOR, self::ROLE);
    }

    public function testRevokesTokenInsideAuditedTransaction(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
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
            self::ACTOR,
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb305',
        );
    }

    private function service(
        AccessControlRepository $repository,
        ?PasswordHasher $passwords = null,
        ?AuditRecorder $audit = null,
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
        );
    }
}
