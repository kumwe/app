<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\BusinessSecurity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Types\Types;
use Joomla\DI\Container;
use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalBinding;
use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalRule;
use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalStatus;
use Kumwe\CMS\BusinessSecurity\Infrastructure\Persistence\DoctrineApprovalRepository;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Proves the three competing-approver races on the configured engine, across two real connections.
 *
 * The unit suite builds a mocked `Connection` and asserts on SQL strings, which can show that a `FOR
 * UPDATE` was written but never that it excludes anybody. Each test here starts a second connection and
 * makes the two sessions collide in one of the three ways a maker-checker workflow can be attacked: the
 * same approver voting twice, two approvers crossing quorum at once, and a consume racing a revoke on
 * one version.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class CompetingApproverContentionIntegrationTest extends TestCase
{
    public function testTheSameApproverCannotVoteTwiceEvenFromASecondConnection(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $secondary = TestKernelFactory::create($environment);
        $requestId = $this->seedRequest($primary, 2);
        $approver = 'approver-alpha-' . substr($requestId, -12);

        $this->repository($primary)->vote(
            Uuid::uuid7()->toString(),
            $requestId,
            $approver,
            'approve',
            null,
            str_repeat('a', 64),
            null,
            $this->now(),
        );

        try {
            $this->repository($secondary)->vote(
                Uuid::uuid7()->toString(),
                $requestId,
                $approver,
                'approve',
                null,
                str_repeat('a', 64),
                null,
                $this->now(),
            );
            self::fail('One actor cannot contribute two votes to the same request.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, $this->repository($primary)->approvalCount($requestId));
        }
    }

    public function testASecondApproverWaitsOnTheLockedRequestAndLosesTheQuorumTransition(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $database = $this->connection($primary);
        $this->skipWithoutRowLocks($database);

        $secondary = TestKernelFactory::create($environment);
        $concurrent = $this->connection($secondary);
        $requestId = $this->seedRequest($primary, 1);
        $this->boundLockWait($concurrent);

        try {
            $database->beginTransaction();
            $held = $this->repository($primary)->lock($requestId);
            self::assertNotNull($held);
            self::assertSame(ApprovalStatus::Pending, $held->status);

            $concurrent->beginTransaction();
            try {
                $this->repository($secondary)->lock($requestId);
                self::fail('A second approver must not read the request while the first holds it.');
            } catch (DbalException) {
                self::assertTrue($concurrent->isTransactionActive());
            }
            $concurrent->rollBack();

            $this->repository($primary)->vote(
                Uuid::uuid7()->toString(),
                $requestId,
                'approver-beta-' . substr($requestId, -12),
                'approve',
                null,
                str_repeat('b', 64),
                null,
                $this->now(),
            );
            $this->repository($primary)->transition(
                $requestId,
                ApprovalStatus::Pending,
                ApprovalStatus::Approved,
                $held->version,
                $this->now(),
            );
            $database->commit();

            try {
                $this->repository($secondary)->transition(
                    $requestId,
                    ApprovalStatus::Pending,
                    ApprovalStatus::Approved,
                    $held->version,
                    $this->now(),
                );
                self::fail('The losing approver cannot re-apply a transition on the version it read.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('changed before its transition', $exception->getMessage());
            }
            self::assertSame(
                [ApprovalStatus::Approved->value, 2],
                $this->state($primary, $requestId),
                'Exactly one quorum transition landed, and it advanced the version by exactly one.',
            );
        } finally {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            if ($concurrent->isTransactionActive()) {
                $concurrent->rollBack();
            }
            $concurrent->close();
        }
    }

    public function testConsumeAndRevokeOnOneVersionCannotBothLand(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $secondary = TestKernelFactory::create($environment);
        $requestId = $this->seedRequest($primary, 1);
        $this->connection($primary)->update(
            $this->tables($primary)->raw('approval_requests'),
            ['status' => ApprovalStatus::Approved->value],
            ['id' => $requestId],
        );
        $approved = $this->repository($primary)->lock($requestId);
        self::assertNotNull($approved);

        $this->repository($primary)->transition(
            $requestId,
            ApprovalStatus::Approved,
            ApprovalStatus::Consumed,
            $approved->version,
            $this->now(),
        );
        try {
            $this->repository($secondary)->transition(
                $requestId,
                ApprovalStatus::Approved,
                ApprovalStatus::Revoked,
                $approved->version,
                $this->now(),
            );
            self::fail('A revoke cannot land on the version a consume already spent.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('changed before its transition', $exception->getMessage());
        }
        self::assertSame(
            [ApprovalStatus::Consumed->value, $approved->version + 1],
            $this->state($primary, $requestId),
        );
    }

    /**
     * Seed one active rule and one pending request against it, both scoped to this test alone.
     */
    private function seedRequest(Container $container, int $quorum): string
    {
        $database = $this->connection($container);
        $tables = $this->tables($container);
        $suffix = substr(str_replace('-', '', Uuid::uuid7()->toString()), -12);
        $ruleId = Uuid::uuid7()->toString();
        $now = $this->now();
        $database->insert($tables->raw('separation_duty_rules'), [
            'id' => $ruleId,
            'site_identifier' => 'default',
            'organization_id' => null,
            'scope_key' => 'contention:' . $suffix,
            'rule_code' => 'contention.approval.' . $suffix,
            'resource_type' => 'business_record',
            'request_action' => 'business.record.update',
            'approval_action' => 'business.record.update',
            'requester_role_id' => null,
            'approver_role_id' => null,
            'quorum' => $quorum,
            'distinct_actors' => true,
            'status' => 'active',
            'version' => 1,
            'created_by' => 'contention-suite',
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            'distinct_actors' => Types::BOOLEAN,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);

        $requestId = Uuid::uuid7()->toString();
        $this->repository($container)->insert(
            $requestId,
            new ApprovalRule(
                $ruleId,
                'contention.approval.' . $suffix,
                'business.record.update',
                $quorum,
                true,
                1,
                null,
            ),
            new ApprovalBinding(
                'requester-' . $suffix,
                'business.record.update',
                'business_record',
                'record-' . $suffix,
                1,
                'default',
                null,
                null,
                str_repeat('c', 64),
                str_repeat('d', 64),
            ),
            $now->modify('+1 day'),
            $now,
        );

        return $requestId;
    }

    /**
     * Read the request's committed status and optimistic version as one pair.
     *
     * @return  array{0: string, 1: int}  Status value and version.
     */
    private function state(Container $container, string $requestId): array
    {
        $row = $this->connection($container)->fetchAssociative(sprintf(
            'SELECT status, version FROM %s WHERE id = ?',
            $this->tables($container)->quoted('approval_requests'),
        ), [$requestId], [Types::GUID]);
        if ($row === false) {
            throw new RuntimeException('The seeded approval request vanished.');
        }

        return [(string) $row['status'], (int) $row['version']];
    }

    private function skipWithoutRowLocks(Connection $database): void
    {
        if ($database->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped(
                'SQLite compiles the approval lock clause to an empty string, so a second session reading '
                . 'the held request proves nothing there and the assertion would pass vacuously.',
            );
        }
    }

    private function boundLockWait(Connection $connection): void
    {
        $connection->executeStatement(
            $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? 'SET innodb_lock_wait_timeout = 1'
                : "SET lock_timeout = '500ms'",
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-14T11:00:00', new DateTimeZone('UTC'));
    }

    private function repository(Container $container): DoctrineApprovalRepository
    {
        return new DoctrineApprovalRepository($this->connection($container), $this->tables($container));
    }

    private function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        if (!$tables instanceof TableNames) {
            throw new RuntimeException('The integration table map is unavailable.');
        }

        return $tables;
    }

    private function connection(Container $container): Connection
    {
        $connection = $container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new RuntimeException('The integration connection is unavailable.');
        }

        return $connection;
    }
}
