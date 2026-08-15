<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Presentation;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Presentation\Application\Preference\PresentationAccessGroup;
use Kumwe\CMS\Presentation\Infrastructure\Persistence\DoctrinePresentationAccessGroupRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the presentation access-group adapter projects only canonical identity roles and assignments.
 *
 * @since  2.0.0
 */
#[CoversClass(PresentationAccessGroup::class)]
#[CoversClass(DoctrinePresentationAccessGroupRepository::class)]
final class PresentationAccessGroupRepositoryIntegrationTest extends TestCase
{
    /**
     * Proves listing, membership filtering, stable IDs, existence, ordering, and SQLite locking fallback.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testExistingRolesAreTheOnlyPresentationAccessGroups(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        $schema = new Schema();
        $roles = $schema->createTable($tables->raw('roles'));
        $roles->addColumn('id', Types::GUID);
        $roles->addColumn('code', Types::STRING, ['length' => 64]);
        $roles->addColumn('name', Types::STRING, ['length' => 191]);
        $roles->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $roles->setPrimaryKey(['id']);
        $assignments = $schema->createTable($tables->raw('user_roles'));
        $assignments->addColumn('user_id', Types::GUID);
        $assignments->addColumn('role_id', Types::GUID);
        $assignments->addColumn('assigned_at', Types::DATETIME_IMMUTABLE);
        $assignments->addColumn('assigned_by', Types::GUID, ['notnull' => false]);
        $assignments->setPrimaryKey(['user_id', 'role_id']);
        foreach ($schema->toSql($database->getDatabasePlatform()) as $statement) {
            $database->executeStatement($statement);
        }

        $userId = '0191574f-f0b8-7bf3-a9aa-91c6b8244f01';
        $financeId = '0191574f-f0b8-7bf3-a9aa-91c6b8244f10';
        $operationsId = '0191574f-f0b8-7bf3-a9aa-91c6b8244f11';
        $unassignedId = '0191574f-f0b8-7bf3-a9aa-91c6b8244f12';
        $at = new DateTimeImmutable('2026-08-15T10:00:00+00:00');
        foreach (
            [
                [$financeId, 'finance-reviewers', 'Finance reviewers'],
                [$operationsId, 'operations', 'Operations'],
                [$unassignedId, 'sales', 'Sales'],
            ] as [$roleId, $code, $name]
        ) {
            $database->insert($tables->raw('roles'), [
                'id' => $roleId,
                'code' => $code,
                'name' => $name,
                'created_at' => $at,
            ], ['id' => Types::GUID, 'created_at' => Types::DATETIME_IMMUTABLE]);
        }
        foreach ([$operationsId, $financeId] as $roleId) {
            $database->insert($tables->raw('user_roles'), [
                'user_id' => $userId,
                'role_id' => $roleId,
                'assigned_at' => $at,
                'assigned_by' => null,
            ], [
                'user_id' => Types::GUID,
                'role_id' => Types::GUID,
                'assigned_at' => Types::DATETIME_IMMUTABLE,
                'assigned_by' => Types::GUID,
            ]);
        }
        $repository = new DoctrinePresentationAccessGroupRepository($database, $tables);

        self::assertSame(
            ['role:' . $financeId, 'role:' . $operationsId],
            array_map(
                static fn (PresentationAccessGroup $group): string => $group->id,
                $repository->listForUser($userId, true),
            ),
        );
        self::assertSame(
            ['role:' . $financeId, 'role:' . $operationsId, 'role:' . $unassignedId],
            array_map(
                static fn (PresentationAccessGroup $group): string => $group->id,
                $repository->listAll(true),
            ),
        );
        self::assertTrue($repository->exists('role:' . $financeId, true));
        self::assertFalse($repository->exists($financeId, true));
        self::assertFalse($repository->exists('role:0191574f-f0b8-7bf3-a9aa-91c6b8244f99', true));
    }
}
