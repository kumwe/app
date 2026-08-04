<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Identity\Infrastructure\Authentication;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\DatabaseQuery;
use Joomla\Database\ParameterType;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Infrastructure\Authentication\PostgreSqlAccessTokenVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PostgreSqlAccessTokenVerifier::class)]
#[UsesClass(AuthenticatedPrincipal::class)]
#[UsesClass(Capability::class)]
final class PostgreSqlAccessTokenVerifierTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';
    private const SUBJECT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    public function testQueriesBySha256DigestAndBuildsPrincipal(): void
    {
        $query = $this->queryMock();
        $query->expects(self::once())
            ->method('bind')
            ->with(':token_digest', hash('sha256', self::TOKEN), ParameterType::STRING)
            ->willReturnSelf();
        $database = $this->databaseMock($query, [
            'subject_id' => self::SUBJECT,
            'capabilities' => '["content.read","content.update"]',
        ]);

        $principal = (new PostgreSqlAccessTokenVerifier($database, 'kumwe'))->verify(self::TOKEN);

        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        self::assertSame(self::SUBJECT, $principal->subject());
        self::assertTrue($principal->hasCapability(Capability::fromString('content.read')));
    }

    public function testRejectsMalformedTokenBeforeDatabaseLookup(): void
    {
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::never())->method('getQuery');

        self::assertNull((new PostgreSqlAccessTokenVerifier($database, 'kumwe'))->verify('short'));
    }

    public function testReturnsNullForUnknownDigest(): void
    {
        $query = $this->queryMock();
        $query->method('bind')->willReturnSelf();
        $database = $this->databaseMock($query, null);

        self::assertNull((new PostgreSqlAccessTokenVerifier($database, 'kumwe'))->verify(self::TOKEN));
    }

    public function testFailsClosedForCorruptStoredCapabilities(): void
    {
        $query = $this->queryMock();
        $query->method('bind')->willReturnSelf();
        $database = $this->databaseMock($query, [
            'subject_id' => self::SUBJECT,
            'capabilities' => '{"content.read":true}',
        ]);

        self::assertNull((new PostgreSqlAccessTokenVerifier($database, 'kumwe'))->verify(self::TOKEN));
    }

    private function queryMock(): DatabaseQuery
    {
        $query = $this->createMock(DatabaseQuery::class);
        $query->method('select')->willReturnSelf();
        $query->method('from')->willReturnSelf();
        $query->method('join')->willReturnSelf();
        $query->method('where')->willReturnSelf();

        return $query;
    }

    /** @param array<string, mixed>|null $row */
    private function databaseMock(DatabaseQuery $query, ?array $row): DatabaseInterface
    {
        $database = $this->createMock(DatabaseInterface::class);
        $database->method('getQuery')->with(true)->willReturn($query);
        $database->method('quoteName')->willReturnCallback(
            static fn (string $name, ?string $alias = null): string => $alias === null
                ? $name
                : $name . ' AS ' . $alias,
        );
        $database->method('quote')->with('active')->willReturn("'active'");
        $database->method('setQuery')->with($query)->willReturnSelf();
        $database->method('loadAssoc')->willReturn($row);

        return $database;
    }
}
