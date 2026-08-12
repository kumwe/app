<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Identity\Infrastructure\Administration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Identity\Infrastructure\Administration\DoctrineAccessControlRepository;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins credential invalidation performed by the Doctrine access-control adapter.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineAccessControlRepository::class)]
final class DoctrineAccessControlRepositoryTest extends TestCase
{
    /**
     * Proves a grant invalidates distinct users assigned directly or through organization membership.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGrantInvalidatesDistinctDirectAndMembershipRoleUsers(): void
    {
        $roleId = '0191574f-f0b8-7bf3-a9aa-91c6b8244f10';
        $directAndMembershipUser = '0191574f-f0b8-7bf3-a9aa-91c6b8244f11';
        $membershipOnlyUser = '0191574f-f0b8-7bf3-a9aa-91c6b8244f12';
        $database = $this->database();
        $database->expects(self::once())->method('insert')->with(
            'kumwe_role_capability_grants',
            self::callback(static fn (array $row): bool => $row['role_id'] === $roleId
                && $row['capability_code'] === 'content.read'),
            self::isType('array'),
        )->willReturn(1);
        $database->expects(self::once())->method('fetchFirstColumn')->with(
            self::callback(static function (string $sql): bool {
                self::assertStringContainsString('kumwe_user_roles', $sql);
                self::assertStringContainsString('kumwe_membership_roles', $sql);
                self::assertStringContainsString('kumwe_organization_memberships', $sql);
                self::assertStringContainsString(' UNION SELECT ', $sql);
                self::assertStringNotContainsString('UNION ALL', $sql);
                self::assertStringNotContainsString('status', $sql);
                self::assertStringNotContainsString('site_identifier', $sql);

                return true;
            }),
            [$roleId, $roleId],
        )->willReturn([$directAndMembershipUser, $membershipOnlyUser]);
        $invalidated = [];
        $database->expects(self::exactly(2))->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $parameters, array $types) use (&$invalidated): int {
                self::assertStringContainsString('security_epoch = security_epoch + 1', $sql);
                self::assertSame([Types::GUID], $types);
                self::assertIsString($parameters[0] ?? null);
                $invalidated[] = $parameters[0];

                return 1;
            },
        );

        $this->repository($database)->grant(
            '0191574f-f0b8-7bf3-a9aa-91c6b8244f13',
            $roleId,
            'content.read',
            'global',
            null,
            '0191574f-f0b8-7bf3-a9aa-91c6b8244f14',
            new DateTimeImmutable('2026-08-09T10:00:00+00:00'),
        );

        self::assertSame([$directAndMembershipUser, $membershipOnlyUser], $invalidated);
    }

    /**
     * Proves revocation performs the same membership-aware invalidation after deleting the grant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRevokeGrantInvalidatesMembershipRoleUsers(): void
    {
        $grantId = '0191574f-f0b8-7bf3-a9aa-91c6b8244f20';
        $roleId = '0191574f-f0b8-7bf3-a9aa-91c6b8244f21';
        $membershipUser = '0191574f-f0b8-7bf3-a9aa-91c6b8244f22';
        $database = $this->database();
        $database->expects(self::once())->method('fetchOne')->with(
            self::stringContains('kumwe_role_capability_grants'),
            [$grantId],
        )->willReturn($roleId);
        $database->expects(self::once())->method('delete')->with(
            'kumwe_role_capability_grants',
            ['id' => $grantId],
        )->willReturn(1);
        $database->expects(self::once())->method('fetchFirstColumn')->with(
            self::stringContains('kumwe_membership_roles'),
            [$roleId, $roleId],
        )->willReturn([$membershipUser]);
        $database->expects(self::once())->method('executeStatement')->with(
            self::stringContains('security_epoch = security_epoch + 1'),
            [$membershipUser],
            [Types::GUID],
        )->willReturn(1);

        $this->repository($database)->revokeGrant($grantId);
    }

    /**
     * Proves organization-wide membership lookup emits no untyped nullable workspace parameter.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOrganizationMembershipWithoutWorkspaceUsesNoNullSentinel(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->with(
            self::callback(static fn (string $sql): bool => !str_contains($sql, '? IS NULL')
                && !str_contains($sql, 'kumwe_membership_workspaces')),
            ['user-id', 'default', 'acme'],
        )->willReturn(false);

        self::assertNull($this->repository($database)->organizationMembershipAuthority(
            'user-id',
            'default',
            'acme',
            null,
        ));
    }

    /**
     * Proves a selected workspace is represented by one typed equality parameter.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOrganizationMembershipWorkspaceUsesOneBoundParameter(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->with(
            self::callback(static fn (string $sql): bool => str_contains(
                $sql,
                'kumwe_membership_workspaces',
            ) && str_contains($sql, 'w.identifier = ?')
                && !str_contains($sql, '? IS NULL')),
            ['user-id', 'default', 'acme', 'finance'],
        )->willReturn([
            'id' => 'membership-id',
            'version' => 3,
            'policy_generation' => 5,
        ]);
        $database->expects(self::once())->method('fetchAllAssociative')->with(
            self::stringContains('kumwe_membership_roles'),
            ['membership-id'],
        )->willReturn([]);

        self::assertSame(
            [
                'membership_id' => 'membership-id',
                'membership_version' => 3,
                'policy_generation' => 5,
                'organization_identifier' => 'acme',
                'workspace_identifier' => 'finance',
                'grants' => [],
            ],
            $this->repository($database)->organizationMembershipAuthority(
                'user-id',
                'default',
                'acme',
                'finance',
            ),
        );
    }

    /**
     * Proves the security timeline selects only bounded identity fields and never stored event metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSecurityEventsUseClosedActionProjectionWithoutMetadata(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAllAssociative')->with(
            self::callback(static function (string $sql): bool {
                self::assertStringContainsString('kumwe_audit_events', $sql);
                self::assertStringContainsString("action LIKE 'identity.step_up.%'", $sql);
                self::assertStringContainsString('ORDER BY occurred_at DESC, id DESC', $sql);
                self::assertStringNotContainsString('metadata', $sql);

                return true;
            }),
        )->willReturn([[
            'id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244f30',
            'occurred_at' => '2026-08-12 10:00:00',
            'actor_id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244f31',
            'action' => 'identity.step_up.challenge',
            'subject_type' => 'step_up_credential',
            'subject_id' => '0191574f-f0b8-7bf3-a9aa-91c6b8244f32',
            'outcome' => 'success',
        ]]);

        $events = $this->repository($database)->securityEvents();

        self::assertCount(1, $events);
        self::assertArrayNotHasKey('metadata', $events[0]);
    }

    /**
     * Build the adapter with the supplied DBAL test double.
     *
     * @param   Connection  $database  Test double recording the adapter's SQL interactions.
     *
     * @return  DoctrineAccessControlRepository  Adapter under test.
     *
     * @since   2.0.0
     */
    private function repository(Connection $database): DoctrineAccessControlRepository
    {
        return new DoctrineAccessControlRepository($database, new TableNames($database, 'kumwe_'));
    }

    /**
     * Create a DBAL test double whose identifier quoting preserves readable table names.
     *
     * @return  Connection  Configured DBAL mock.
     *
     * @since   2.0.0
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
