<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Presentation\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Presentation\Persistence\DoctrinePresentationAccessGroupRepository;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * Proves effective groups combine direct and exact-current-membership assignments in canonical order.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testContextProjectionUsesOnlyDirectAndExactMembershipAssignments(): void
    {
        $actorId = '0191574f-f0b8-7bf3-a9aa-91c6b8244f01';
        $membershipId = '0191574f-f0b8-7bf3-a9aa-91c6b8244f02';
        $database = $this->database();
        $database->expects(self::once())->method('fetchAllAssociative')->with(
            self::callback(static function (string $sql): bool {
                self::assertStringContainsString('FROM kumwe_user_roles ur', $sql);
                self::assertStringContainsString('FROM kumwe_membership_roles mr', $sql);
                self::assertStringContainsString('mr.membership_id = ?', $sql);
                self::assertStringContainsString('ORDER BY r.code, r.id', $sql);
                self::assertStringContainsString('LIMIT 2', $sql);
                self::assertStringNotContainsString('FOR UPDATE', $sql);

                return true;
            }),
            [$actorId, $membershipId],
            [Types::GUID, Types::GUID],
        )->willReturn([
            [
                'id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244f10',
                'code' => 'finance-reviewers',
                'name' => 'Finance reviewers',
            ],
            [
                'id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244f11',
                'code' => 'operations',
                'name' => 'Operations',
            ],
        ]);

        $catalog = $this->repository($database)->listForContext(AuthorizationContext::human(
            [],
            $actorId,
            membership: AuthorizationContext::membership(membershipId: $membershipId),
        ), 1);

        self::assertSame('role:0191574f-f0b8-7bf3-a9aa-91c6b8244f10', $catalog->groups[0]->id);
        self::assertTrue($catalog->hasNext());
        self::assertSame('role:0191574f-f0b8-7bf3-a9aa-91c6b8244f11', $catalog->lookahead?->id);
    }

    /**
     * Proves effective-role callers cannot request a prefix larger than the preference batch contract permits.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContextProjectionRejectsAnUnboundedLimitBeforeDatabaseAccess(): void
    {
        $database = $this->database();
        $database->expects(self::never())->method('fetchAllAssociative');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be 1 to 250');

        $this->repository($database)->listForContext(AuthorizationContext::human([]), 251);
    }

    /**
     * Proves paging uses a bounded offset and escapes SQL wildcards in a literal normalized search.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testCatalogUsesAOneExtraRowBoundAndCanonicalCodeOrder(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAllAssociative')->with(
            self::callback(static function (string $sql): bool {
                self::assertStringContainsString("LOWER(code) LIKE ? ESCAPE '!'", $sql);
                self::assertStringContainsString("LOWER(name) LIKE ? ESCAPE '!'", $sql);
                self::assertStringContainsString('ORDER BY code, id LIMIT 2 OFFSET 64', $sql);

                return true;
            }),
            ['%100!%!_!!%', '%100!%!_!!%'],
            [Types::STRING, Types::STRING],
        )->willReturn([
            [
                'id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244f10',
                'code' => 'finance-reviewers',
                'name' => 'Finance reviewers',
            ],
            [
                'id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244f11',
                'code' => 'operations',
                'name' => 'Operations',
            ],
        ]);

        $catalog = $this->repository($database)->catalog(1, 64, '100%_!');

        self::assertTrue($catalog->hasNext());
        self::assertSame(
            ['role:0191574f-f0b8-7bf3-a9aa-91c6b8244f10'],
            array_map(static fn (PresentationAccessGroup $group): string => $group->id, $catalog->groups),
        );
    }

    /**
     * Proves direct adapter callers cannot bypass offset or normalized-search bounds.
     *
     * @param   int     $offset  Candidate offset.
     * @param   string  $search  Candidate search.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('invalidCatalogQueries')]
    public function testRejectsInvalidCatalogQueryBeforeDatabaseAccess(int $offset, string $search): void
    {
        $database = $this->database();
        $database->expects(self::never())->method('fetchAllAssociative');
        $this->expectException(\InvalidArgumentException::class);

        $this->repository($database)->catalog(1, $offset, $search);
    }

    /**
     * Supply direct adapter query values outside the bounded normalized port contract.
     *
     * @return  iterable<string, array{int, string}>  Invalid offset and search pairs.
     *
     * @since   2.0.0
     */
    public static function invalidCatalogQueries(): iterable
    {
        yield 'negative offset' => [-1, ''];
        yield 'huge offset' => [10_001, ''];
        yield 'overlong search' => [0, str_repeat('a', 65)];
        yield 'padded search' => [0, ' finance'];
        yield 'control search' => [0, "finance\nreviewers"];
        yield 'doubled whitespace' => [0, 'finance  reviewers'];
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
