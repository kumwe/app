<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Identity\Infrastructure\Administration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Identity\Infrastructure\Administration\DoctrineAdministratorSessionStore;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(DoctrineAdministratorSessionStore::class)]
final class DoctrineAdministratorSessionStoreTest extends TestCase
{
    private const SESSION_ONE = '018f22e2-7c8b-7ab0-8f3a-88e8026bb410';
    private const SESSION_TWO = '018f22e2-7c8b-7ab0-8f3a-88e8026bb411';

    public function testDeleteRemovesExactSessionOwnershipInTheSameTransaction(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('delete')->with(
            'kumwe_administrator_sessions',
            ['id' => self::SESSION_ONE],
        )->willReturn(1);
        $ownership = $this->createMock(ResourceSiteOwnershipWriter::class);
        $ownership->expects(self::once())->method('remove')->with(
            self::callback(static fn (AuthorizationResource $resource): bool =>
                $resource->type() === 'administrator_session'
                && $resource->identifier() === self::SESSION_ONE),
            self::callback(static fn (SiteContext $site): bool => $site->identifier() === SiteContext::DEFAULT),
        );
        $transactions = $this->transactionManager();

        $this->store($database, $transactions, $ownership)->delete(
            AuthorizationContext::human(['administrator.access']),
            self::SESSION_ONE,
        );
    }

    public function testPurgeDeletesOnlyLockedSessionsForTheAuthorizedSiteAndTheirOwnership(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchFirstColumn')->with(
            self::stringContains('o.site_identifier = ?'),
            self::callback(static fn (array $parameters): bool =>
                $parameters[0] === 'administrator_session'
                && $parameters[1] === SiteContext::DEFAULT
                && $parameters[2] instanceof DateTimeImmutable),
            self::isType('array'),
        )->willReturn([self::SESSION_ONE, self::SESSION_TWO]);
        $deleted = [];
        $database->expects(self::exactly(2))->method('delete')->willReturnCallback(
            static function (string $table, array $criteria) use (&$deleted): int {
                self::assertSame('kumwe_administrator_sessions', $table);
                $sessionId = $criteria['id'] ?? null;
                self::assertIsString($sessionId);
                $deleted[] = $sessionId;

                return 1;
            },
        );
        $removed = [];
        $ownership = $this->createMock(ResourceSiteOwnershipWriter::class);
        $ownership->expects(self::exactly(2))->method('remove')->with(
            self::callback(static function (AuthorizationResource $resource) use (&$removed): bool {
                $removed[] = $resource->identifier();

                return $resource->type() === 'administrator_session';
            }),
            self::callback(static fn (SiteContext $site): bool => $site->identifier() === SiteContext::DEFAULT),
        );

        $count = $this->store($database, $this->transactionManager(), $ownership)->purgeExpired(
            AuthorizationContext::human(['automation.manage']),
        );

        self::assertSame(2, $count);
        self::assertSame([self::SESSION_ONE, self::SESSION_TWO], $deleted);
        self::assertSame([self::SESSION_ONE, self::SESSION_TWO], $removed);
    }

    private function store(
        Connection $database,
        TransactionManager $transactions,
        ResourceSiteOwnershipWriter $ownership,
    ): DoctrineAdministratorSessionStore {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-05T10:00:00+00:00'));

        return new DoctrineAdministratorSessionStore(
            $database,
            new TableNames($database, 'kumwe_'),
            $clock,
            str_repeat('s', 64),
            $this->createStub(AuthorizationGateway::class),
            $transactions,
            $ownership,
            AuthorizationContext::provenance(),
        );
    }

    private function database(): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );

        return $database;
    }

    private function transactionManager(): TransactionManager
    {
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->expects(self::once())->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return $transactions;
    }
}
