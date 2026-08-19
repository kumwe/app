<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Extension;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\App\Extension\Runtime\RuntimeLeaseWriter;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Guards the replica lease row against the write contention that failed production acceptance.
 *
 * Several processes in one deployment share a single lease identity: `RuntimeIdentity` hashes the
 * deployment, replica, process and instance names, so every php-fpm child in a container, the container
 * health check and every operator command run inside that container derive the same `replica_id`. They
 * therefore all write one row. MariaDB 11.6.1 and later enable `innodb_snapshot_isolation` by default,
 * which fails a write against a row another transaction committed after the writer's read view opened
 * with ER_CHECKREAD (1020) rather than silently taking the newer version. A lease write issued inside an
 * integration consumer's transaction — whose read view was opened by that consumer's own bookkeeping
 * reads — is exactly that shape, so it failed the event instead of renewing a lease.
 */
#[CoversClass(ExtensionRuntimeMapCompiler::class)]
#[CoversClass(RuntimeLeaseWriter::class)]
final class RuntimeMaterializationContentionIntegrationTest extends TestCase
{
    public function testLeaseRenewalSurvivesAPeerWriteInsideACallerTransaction(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $compiler = $container->get(ExtensionRuntimeMapCompiler::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(ExtensionRuntimeMapCompiler::class, $compiler);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);

        if (!$database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Snapshot-isolation lease contention is a MySQL-family server behaviour.');
        }
        $setting = $database->fetchAssociative("SHOW VARIABLES LIKE 'innodb_snapshot_isolation'");
        if ($setting === false) {
            self::markTestSkipped('This server has no innodb_snapshot_isolation setting to exercise.');
        }
        // MariaDB 11.6.1 and later default this on; forcing it reproduces the production server here.
        $database->executeStatement('SET SESSION innodb_snapshot_isolation = ON');

        $state = $compiler->reconcileAndMaterialize();
        $table = $tables->quoted('extension_runtime_materializations');
        $peer = DriverManager::getConnection($database->getParams());

        $database->beginTransaction();
        try {
            // The consumer transaction opens its read view with the reads its own bookkeeping runs
            // before it re-asserts the runtime generation.
            $database->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));

            // A peer process sharing this lease identity renews the very same row and commits.
            $renewed = new \DateTimeImmutable('+120 seconds');
            $peer->executeStatement(
                sprintf('UPDATE %s SET last_seen_at = ?, lease_until = ? WHERE replica_id = ?', $table),
                [new \DateTimeImmutable(), $renewed, $state->replicaId],
                [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::STRING],
            );

            // Before the fix this raised DriverException(1020) and failed the integration event.
            $compiler->assertLoadedGenerationCurrent($state);
            $database->commit();
        } catch (Throwable $failure) {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }
            throw $failure;
        } finally {
            $peer->close();
        }

        $lease = $database->fetchOne(
            sprintf('SELECT lease_until FROM %s WHERE replica_id = ?', $table),
            [$state->replicaId],
        );
        self::assertIsString($lease, 'The peer-renewed lease row must survive the caller transaction.');
    }

    public function testLeaseIsRenewedOutsideCallerTransactionsOnEveryPlatform(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $compiler = $container->get(ExtensionRuntimeMapCompiler::class);
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(ExtensionRuntimeMapCompiler::class, $compiler);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);

        $state = $compiler->reconcileAndMaterialize();
        $table = $tables->quoted('extension_runtime_materializations');
        $read = sprintf('SELECT last_seen_at FROM %s WHERE replica_id = ?', $table);
        $before = $database->fetchOne($read, [$state->replicaId]);
        self::assertIsString($before);

        // A lease write inside the caller's transaction is what met the concurrent peer write, and it
        // would in any case be rolled back with a failing handler. The generation check still runs.
        $database->beginTransaction();
        $compiler->assertLoadedGenerationCurrent($state);
        $database->commit();
        self::assertSame(
            $before,
            $database->fetchOne($read, [$state->replicaId]),
            'The lease must not be written inside the caller transaction.',
        );

        // The same call outside a transaction still renews the lease, so nothing pins a retired tree
        // for less time than it used to.
        usleep(2_000);
        $compiler->assertLoadedGenerationCurrent($state);
        $after = $database->fetchOne($read, [$state->replicaId]);
        self::assertIsString($after);
        self::assertNotSame($before, $after, 'The lease must still be renewed outside a transaction.');
    }
}
