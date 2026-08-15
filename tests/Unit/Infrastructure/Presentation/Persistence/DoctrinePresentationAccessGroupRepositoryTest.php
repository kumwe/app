<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Presentation\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Presentation\Persistence\DoctrinePresentationAccessGroupRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins fail-closed identity parsing and caller-owned locking in the Doctrine access-group projection.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrinePresentationAccessGroupRepository::class)]
final class DoctrinePresentationAccessGroupRepositoryTest extends TestCase
{
    /**
     * Proves a locked user projection takes a PostgreSQL row lock after deterministic ordering.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testLockedMembershipProjectionUsesForUpdateWhereSupported(): void
    {
        $database = $this->database();
        $database->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $database->expects(self::once())->method('fetchAllAssociative')->with(
            self::callback(static function (string $sql): bool {
                self::assertStringContainsString('INNER JOIN kumwe_user_roles', $sql);
                self::assertStringContainsString('ORDER BY r.name, r.code, r.id', $sql);
                self::assertStringEndsWith(' FOR UPDATE', $sql);

                return true;
            }),
            ['0191574f-f0b8-7bf3-a9aa-91c6b8244f01'],
            [Types::GUID],
        )->willReturn([[
            'id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244f10',
            'code' => 'finance-reviewers',
            'name' => 'Finance reviewers',
        ]]);

        $groups = $this->repository($database)->listForUser(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244f01',
            true,
        );

        self::assertSame('role:0191574f-f0b8-7bf3-a9aa-91c6b8244f10', $groups[0]->id);
    }

    /**
     * Proves a malformed stable identity is rejected without touching the database.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testMalformedExistenceIdentityFailsBeforeAQuery(): void
    {
        $database = $this->database();
        $database->expects(self::never())->method('fetchOne');

        self::assertFalse($this->repository($database)->exists(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244f10',
            true,
        ));
    }

    /**
     * Proves existence locking decodes the stable identity and binds its UUID with the portable DBAL type.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testLockedExistenceReadUsesCanonicalRoleUuid(): void
    {
        $roleId = '0191574f-f0b8-7bf3-a9aa-91c6b8244f10';
        $database = $this->database();
        $database->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $database->expects(self::once())->method('fetchOne')->with(
            self::callback(static fn (string $sql): bool => str_ends_with($sql, ' FOR UPDATE')),
            [$roleId],
            [Types::GUID],
        )->willReturn($roleId);

        self::assertTrue($this->repository($database)->exists('role:' . $roleId, true));
    }

    /**
     * Build the adapter with the tested connection and canonical table prefix.
     *
     * @param   Connection  $database  Mock connection observing generated SQL.
     *
     * @return  DoctrinePresentationAccessGroupRepository
     *
     * @since  2.0.0
     */
    private function repository(Connection $database): DoctrinePresentationAccessGroupRepository
    {
        return new DoctrinePresentationAccessGroupRepository($database, new TableNames($database, 'kumwe_'));
    }

    /**
     * Build a connection mock that leaves validated table names unquoted for readable SQL assertions.
     *
     * @return  Connection
     *
     * @since  2.0.0
     */
    private function database(): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );

        return $database;
    }
}
