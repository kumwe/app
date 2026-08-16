<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Presentation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\CMS\Application\Presentation\Preference\PresentationAccessGroupRepository;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Presentation\Persistence\DoctrinePresentationAccessGroupRepository;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

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
        $membershipAssignments = $schema->createTable($tables->raw('membership_roles'));
        $membershipAssignments->addColumn('membership_id', Types::GUID);
        $membershipAssignments->addColumn('role_id', Types::GUID);
        $membershipAssignments->addColumn('assigned_at', Types::DATETIME_IMMUTABLE);
        $membershipAssignments->addColumn('assigned_by', Types::GUID, ['notnull' => false]);
        $membershipAssignments->setPrimaryKey(['membership_id', 'role_id']);
        foreach ($schema->toSql($database->getDatabasePlatform()) as $statement) {
            $database->executeStatement($statement);
        }

        $userId = '0191574f-f0b8-7bf3-a9aa-91c6b8244f01';
        $membershipId = '0191574f-f0b8-7bf3-a9aa-91c6b8244f02';
        $unrelatedMembershipId = '0191574f-f0b8-7bf3-a9aa-91c6b8244f03';
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
        $database->insert($tables->raw('user_roles'), [
            'user_id' => $userId,
            'role_id' => $financeId,
            'assigned_at' => $at,
            'assigned_by' => null,
        ], [
            'user_id' => Types::GUID,
            'role_id' => Types::GUID,
            'assigned_at' => Types::DATETIME_IMMUTABLE,
            'assigned_by' => Types::GUID,
        ]);
        foreach ([[$membershipId, $operationsId], [$unrelatedMembershipId, $unassignedId]] as $assignment) {
            $database->insert($tables->raw('membership_roles'), [
                'membership_id' => $assignment[0],
                'role_id' => $assignment[1],
                'assigned_at' => $at,
                'assigned_by' => null,
            ], [
                'membership_id' => Types::GUID,
                'role_id' => Types::GUID,
                'assigned_at' => Types::DATETIME_IMMUTABLE,
                'assigned_by' => Types::GUID,
            ]);
        }
        $repository = new DoctrinePresentationAccessGroupRepository($database, $tables);
        $context = AuthorizationContext::human(
            [],
            $userId,
            membership: AuthorizationContext::membership(membershipId: $membershipId),
        );

        self::assertSame(
            ['role:' . $financeId, 'role:' . $operationsId],
            array_map(
                static fn (PresentationAccessGroup $group): string => $group->id,
                $repository->listForContext($context, 250)->groups,
            ),
        );
        $catalog = $repository->catalog(2);
        self::assertSame(
            ['role:' . $financeId, 'role:' . $operationsId],
            array_map(
                static fn (PresentationAccessGroup $group): string => $group->id,
                $catalog->groups,
            ),
        );
        self::assertTrue($catalog->hasNext());
        self::assertFalse($repository->catalog(3)->hasNext());
        self::assertTrue($repository->exists('role:' . $financeId, true));
        self::assertFalse($repository->exists($financeId, true));
        self::assertFalse($repository->exists('role:0191574f-f0b8-7bf3-a9aa-91c6b8244f99', true));

        $tailIds = [];
        for ($index = 1; $index <= 70; $index++) {
            $roleId = sprintf('0191574f-f0b8-7bf3-a9ab-%012x', $index);
            $tailIds[] = $roleId;
            $database->insert($tables->raw('roles'), [
                'id' => $roleId,
                'code' => sprintf('tail-role-%03d', $index),
                'name' => $index === 70 ? 'Literal 100%_! role' : sprintf('Tail role %03d', $index),
                'created_at' => $at,
            ], ['id' => Types::GUID, 'created_at' => Types::DATETIME_IMMUTABLE]);
        }
        $tail = $repository->catalog(1, 64, 'tail-role-');
        self::assertSame('role:' . $tailIds[64], $tail->groups[0]->id);
        self::assertTrue($tail->hasNext());
        $literal = $repository->catalog(1, 0, '100%_!');
        self::assertSame('role:' . $tailIds[69], $literal->groups[0]->id);
        self::assertFalse($literal->hasNext());
    }

    /**
     * Proves the production-wired projection and its locking reads execute on every supported database.
     *
     * The merge matrix runs this method once on MariaDB, MySQL, and PostgreSQL. Fixture rows live inside
     * one caller-owned transaction that is always rolled back, so the idempotency pass observes the same
     * database it received before the test while each non-SQLite platform executes the real `FOR UPDATE`
     * queries against canonical role and assignment tables.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalProjectionExecutesAcrossTheSupportedDatabaseMatrix(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        $repository = $container->get(PresentationAccessGroupRepository::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        self::assertInstanceOf(DoctrinePresentationAccessGroupRepository::class, $repository);
        if ($database->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped(
                'The supported-database projection check requires the MariaDB, MySQL, or PostgreSQL CI service.',
            );
        }

        $actorId = TestKernelFactory::administratorContext($container)->actorId();
        $suffix = substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 12);
        $financeId = Uuid::uuid7()->toString();
        $operationsId = Uuid::uuid7()->toString();
        $unassignedId = Uuid::uuid7()->toString();
        $missingId = Uuid::uuid7()->toString();
        $currentOrganizationId = Uuid::uuid7()->toString();
        $unrelatedOrganizationId = Uuid::uuid7()->toString();
        $currentMembershipId = Uuid::uuid7()->toString();
        $unrelatedMembershipId = Uuid::uuid7()->toString();
        $at = new DateTimeImmutable('2026-08-15T10:00:00+00:00');
        $currentOrganization = 'dashboard-current-' . $suffix;
        $unrelatedOrganization = 'dashboard-unrelated-' . $suffix;

        $database->beginTransaction();
        try {
            foreach (
                [
                    [$financeId, 'dashboard-finance-' . $suffix, 'Dashboard matrix Finance ' . $suffix],
                    [$operationsId, 'dashboard-operations-' . $suffix, 'Dashboard matrix Operations ' . $suffix],
                    [$unassignedId, 'dashboard-sales-' . $suffix, 'Dashboard matrix Sales ' . $suffix],
                ] as [$roleId, $code, $name]
            ) {
                $database->insert($tables->raw('roles'), [
                    'id' => $roleId,
                    'code' => $code,
                    'name' => $name,
                    'created_at' => $at,
                ], [
                    'id' => Types::GUID,
                    'created_at' => Types::DATETIME_IMMUTABLE,
                ]);
            }
            $pageIds = [];
            $pageSearch = 'dashboard-page-' . $suffix;
            $literalSearch = '100%_!-' . $suffix;
            for ($index = 1; $index <= 65; $index++) {
                $pageId = Uuid::uuid7()->toString();
                $pageIds[] = $pageId;
                $database->insert($tables->raw('roles'), [
                    'id' => $pageId,
                    'code' => sprintf('%s-%03d', $pageSearch, $index),
                    'name' => $index === 65
                        ? 'Dashboard literal ' . $literalSearch
                        : sprintf('Dashboard page %03d %s', $index, $suffix),
                    'created_at' => $at,
                ], [
                    'id' => Types::GUID,
                    'created_at' => Types::DATETIME_IMMUTABLE,
                ]);
            }
            $database->insert($tables->raw('user_roles'), [
                'user_id' => $actorId,
                'role_id' => $financeId,
                'assigned_at' => $at,
                'assigned_by' => $actorId,
            ], [
                'user_id' => Types::GUID,
                'role_id' => Types::GUID,
                'assigned_at' => Types::DATETIME_IMMUTABLE,
                'assigned_by' => Types::GUID,
            ]);
            foreach (
                [
                    [$currentOrganizationId, $currentOrganization],
                    [$unrelatedOrganizationId, $unrelatedOrganization],
                ] as [$organizationId, $identifier]
            ) {
                $database->insert($tables->raw('organizations'), [
                    'id' => $organizationId,
                    'site_identifier' => 'default',
                    'identifier' => $identifier,
                    'name' => 'Dashboard matrix ' . $identifier,
                    'status' => 'active',
                    'policy_generation' => 1,
                    'version' => 1,
                    'created_at' => $at,
                    'updated_at' => $at,
                ], [
                    'id' => Types::GUID,
                    'created_at' => Types::DATETIME_IMMUTABLE,
                    'updated_at' => Types::DATETIME_IMMUTABLE,
                ]);
            }
            foreach (
                [
                    [$currentMembershipId, $currentOrganizationId, $operationsId],
                    [$unrelatedMembershipId, $unrelatedOrganizationId, $unassignedId],
                ] as [$membershipId, $organizationId, $roleId]
            ) {
                $database->insert($tables->raw('organization_memberships'), [
                    'id' => $membershipId,
                    'organization_id' => $organizationId,
                    'user_id' => $actorId,
                    'status' => 'active',
                    'version' => 1,
                    'valid_from' => $at->modify('-1 day'),
                    'valid_until' => null,
                    'created_by' => $actorId,
                    'created_at' => $at,
                    'updated_at' => $at,
                ], [
                    'id' => Types::GUID,
                    'organization_id' => Types::GUID,
                    'user_id' => Types::GUID,
                    'valid_from' => Types::DATETIME_IMMUTABLE,
                    'valid_until' => Types::DATETIME_IMMUTABLE,
                    'created_at' => Types::DATETIME_IMMUTABLE,
                    'updated_at' => Types::DATETIME_IMMUTABLE,
                ]);
                $database->insert($tables->raw('membership_roles'), [
                    'membership_id' => $membershipId,
                    'role_id' => $roleId,
                    'assigned_by' => $actorId,
                    'assigned_at' => $at,
                ], [
                    'membership_id' => Types::GUID,
                    'role_id' => Types::GUID,
                    'assigned_at' => Types::DATETIME_IMMUTABLE,
                ]);
            }
            $context = AuthorizationContext::human(
                [],
                $actorId,
                membership: AuthorizationContext::membership(
                    organization: $currentOrganization,
                    membershipId: $currentMembershipId,
                ),
            );

            $effective = array_values(array_filter(
                $repository->listForContext($context, 250)->groups,
                static fn (PresentationAccessGroup $group): bool => in_array($group->id, [
                    'role:' . $financeId,
                    'role:' . $operationsId,
                    'role:' . $unassignedId,
                ], true),
            ));

            self::assertSame(
                ['role:' . $financeId, 'role:' . $operationsId],
                array_map(static fn (PresentationAccessGroup $group): string => $group->id, $effective),
            );
            self::assertTrue($repository->exists('role:' . $financeId, true));
            self::assertFalse($repository->exists($financeId, true));
            self::assertFalse($repository->exists('role:' . $missingId, true));
            $tail = $repository->catalog(1, 64, $pageSearch);
            self::assertSame('role:' . $pageIds[64], $tail->groups[0]->id);
            self::assertFalse($tail->hasNext());
            $literal = $repository->catalog(1, 0, $literalSearch);
            self::assertSame('role:' . $pageIds[64], $literal->groups[0]->id);
            self::assertFalse($literal->hasNext());
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
        }
    }
}
