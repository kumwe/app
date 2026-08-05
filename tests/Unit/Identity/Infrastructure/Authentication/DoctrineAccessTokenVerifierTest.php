<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Identity\Infrastructure\Authentication;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Application\Authentication\PrincipalGrant;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\GrantScope;
use Kumwe\CMS\Identity\Infrastructure\Authentication\DoctrineAccessTokenVerifier;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Kumwe\CMS\Tests\Support\AuthorizationContext;

#[CoversClass(DoctrineAccessTokenVerifier::class)]
#[UsesClass(AuthenticatedPrincipal::class)]
#[UsesClass(Capability::class)]
#[UsesClass(GrantScope::class)]
#[UsesClass(PrincipalGrant::class)]
final class DoctrineAccessTokenVerifierTest extends TestCase
{
    private const string TOKEN = 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';
    private const string SUBJECT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    public function testRemovedPermissionIsAbsentImmediatelyDespiteTokenSnapshot(): void
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => $name);
        $database->expects(self::once())->method('fetchAssociative')->willReturn([
            'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
            'subject_id' => self::SUBJECT,
            'capabilities' => '["content.read","content.update","content.delete"]',
            'last_used_at' => null,
            'security_epoch' => 1,
            'site_identifier' => 'default',
        ]);
        $database->expects(self::once())->method('fetchAllAssociative')->willReturn([
            ['capability' => 'content.read', 'scope_type' => 'site', 'scope_identifier' => 'default'],
            ['capability' => 'content.update', 'scope_type' => 'content_type', 'scope_identifier' => 'news'],
        ]);
        $database->expects(self::once())->method('executeStatement');

        $principal = (new DoctrineAccessTokenVerifier(
            $database,
            new TableNames($database, 'kumwe_'),
            $this->clock(),
            AuthorizationContext::provenance(),
        ))
            ->verify(self::TOKEN);

        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        self::assertTrue($principal->hasCapability(Capability::fromString('content.read')));
        self::assertTrue($principal->hasCapability(Capability::fromString('content.update')));
        self::assertFalse($principal->hasCapability(Capability::fromString('content.delete')));
        self::assertTrue($principal->allows(
            Capability::fromString('content.read'),
            [GrantScope::named('site', 'default')],
        ));
        self::assertFalse($principal->allows(
            Capability::fromString('content.read'),
            [GrantScope::named('site', 'other-site')],
        ));
        self::assertTrue($principal->allows(
            Capability::fromString('content.update'),
            [GrantScope::named('content_type', 'news')],
        ));
        self::assertFalse($principal->allows(
            Capability::fromString('content.update'),
            [GrantScope::named('content_type', 'events')],
        ));
    }

    public function testRejectsMalformedTokenBeforeDatabaseLookup(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('fetchAssociative');

        self::assertNull((new DoctrineAccessTokenVerifier(
            $database,
            new TableNames($database, 'kumwe_'),
            $this->clock(),
            AuthorizationContext::provenance(),
        ))
            ->verify('short'));
    }

    public function testRejectsUnknownAudiencePurposeBeforeDatabaseLookup(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('fetchAssociative');

        self::assertNull((new DoctrineAccessTokenVerifier(
            $database,
            new TableNames($database, 'kumwe_'),
            $this->clock(),
            AuthorizationContext::provenance(),
        ))->verify(self::TOKEN, 'kumwe-http', 'management', 'default'));
    }

    public function testBindsEveryLookupToAdapterAndSiteContext(): void
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => $name);
        $database->expects(self::exactly(2))->method('fetchAssociative')->with(
            self::stringContains('INNER JOIN kumwe_sites s'),
            self::callback(static function (array $parameters): bool {
                return $parameters === [hash('sha256', self::TOKEN), 'kumwe-cli', 'management', 'default', true]
                    || $parameters === [hash('sha256', self::TOKEN), 'kumwe-http', 'api', 'other-site', true];
            }),
            self::anything(),
        )->willReturn(false);
        $verifier = new DoctrineAccessTokenVerifier(
            $database,
            new TableNames($database, 'kumwe_'),
            $this->clock(),
            AuthorizationContext::provenance(),
        );

        self::assertNull($verifier->verify(self::TOKEN, 'kumwe-cli', 'management', 'default'));
        self::assertNull($verifier->verify(self::TOKEN, 'kumwe-http', 'api', 'other-site'));
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-04T12:00:00+00:00'));

        return $clock;
    }
}
