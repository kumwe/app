<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Identity\Infrastructure\Authentication;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Infrastructure\Authentication\DoctrineAccessTokenVerifier;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(DoctrineAccessTokenVerifier::class)]
final class DoctrineAccessTokenVerifierTest extends TestCase
{
    private const string TOKEN = 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';
    private const string SUBJECT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    public function testBuildsPrincipalFromPortableDatabaseRow(): void
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteIdentifier')->willReturnCallback(static fn (string $name): string => $name);
        $database->expects(self::once())->method('fetchAssociative')->willReturn([
            'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
            'subject_id' => self::SUBJECT,
            'capabilities' => '["content.read","content.update"]',
            'last_used_at' => null,
        ]);
        $database->expects(self::once())->method('executeStatement');

        $principal = (new DoctrineAccessTokenVerifier(
            $database,
            new TableNames($database, 'kumwe_'),
            $this->clock(),
        ))
            ->verify(self::TOKEN);

        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        self::assertTrue($principal->hasCapability(Capability::fromString('content.read')));
    }

    public function testRejectsMalformedTokenBeforeDatabaseLookup(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('fetchAssociative');

        self::assertNull((new DoctrineAccessTokenVerifier(
            $database,
            new TableNames($database, 'kumwe_'),
            $this->clock(),
        ))
            ->verify('short'));
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-04T12:00:00+00:00'));

        return $clock;
    }
}
