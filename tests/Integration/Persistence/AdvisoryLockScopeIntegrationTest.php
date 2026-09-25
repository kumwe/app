<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Kumwe\App\BusinessSchema\Infrastructure\Execution\DoctrineBusinessSchemaExecutionLock;
use Kumwe\App\Demo\Infrastructure\Persistence\DoctrineDemoProfileLedger;
use Kumwe\App\Extension\Infrastructure\Trust\DoctrineTrustStoreRepository;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\Migration\DoctrineMigrationLock;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Infrastructure\Time\SystemClock;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Kernel\Configuration\DatabaseConfiguration;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Proves named advisory locks are scoped per database and prefix, so installations sharing a server never
 * contend, while two sessions of one installation still exclude each other exactly as before.
 *
 * MySQL-family `GET_LOCK` names are global to the server. The second database here is one every account
 * can reach without privileges — `information_schema` on MariaDB and MySQL, `postgres` on PostgreSQL —
 * and no table in it is read or written: only the lock is taken.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineTrustStoreRepository::class)]
final class AdvisoryLockScopeIntegrationTest extends TestCase
{
    /**
     * Another database with the same prefix takes the lifecycle lock while this one holds it; a second
     * session of the same installation is still refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLifecycleLocksOfTwoDatabasesOnOneServerDoNotContend(): void
    {
        [$home, $sibling, $other, $prefix] = $this->sessions();
        $holder = new DoctrineTrustStoreRepository($home, new TableNames($home, $prefix));
        $sameInstallation = new DoctrineTrustStoreRepository($sibling, new TableNames($sibling, $prefix));
        $otherInstallation = new DoctrineTrustStoreRepository($other, new TableNames($other, $prefix));
        try {
            $outcome = $holder->synchronizedLifecycle(function () use ($sameInstallation, $otherInstallation): array {
                $refused = false;
                try {
                    $sameInstallation->synchronizedLifecycle(static fn (): bool => true);
                } catch (RuntimeException) {
                    $refused = true;
                }

                return [$refused, $otherInstallation->synchronizedLifecycle(static fn (): string => 'ran')];
            });
            self::assertTrue($outcome[0], 'A second session of the same installation must still be refused.');
            self::assertSame('ran', $outcome[1], 'Another database on the same server must not be blocked.');
            self::assertTrue($sameInstallation->synchronizedLifecycle(static fn (): bool => true));
        } finally {
            foreach ([$home, $sibling, $other] as $connection) {
                $connection->close();
            }
        }
    }

    /**
     * Every other named advisory lock already digests the database identity with the prefix.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryNamedAdvisoryLockDiffersAcrossDatabasesAndMatchesWithinOne(): void
    {
        [$home, $sibling, $other, $prefix] = $this->sessions();
        $clock = new SystemClock();
        try {
            $names = [];
            foreach (['home' => $home, 'sibling' => $sibling, 'other' => $other] as $label => $connection) {
                $tables = new TableNames($connection, $prefix);
                $names[$label] = [
                    $this->invoke(new DoctrineTrustStoreRepository($connection, $tables), 'lifecycleLockName', [
                        $connection->getDatabasePlatform(),
                    ]),
                    $this->invoke(new DoctrineMigrationLock($connection, $tables), 'advisoryIdentity', [])[1],
                    $this->invoke(
                        new DoctrineBusinessSchemaExecutionLock($connection, $tables, $clock),
                        'identity',
                        ['00000000-0000-7000-8000-000000000001'],
                    )[1],
                    $this->invoke(new DoctrineDemoProfileLedger($connection, $tables, $clock), 'advisoryIdentity', [
                        'default',
                    ])[1],
                ];
            }
            self::assertSame($names['home'], $names['sibling']);
            foreach ($names['home'] as $index => $name) {
                self::assertIsString($name);
                self::assertLessThanOrEqual(64, strlen($name));
                self::assertNotSame($name, $names['other'][$index]);
            }
        } finally {
            foreach ([$home, $sibling, $other] as $connection) {
                $connection->close();
            }
        }
    }

    /**
     * Open two sessions on the configured database and one on another database of the same server.
     *
     * @return  array{Connection, Connection, Connection, string}  Home, sibling, other and the prefix.
     *
     * @since   2.0.0
     */
    private function sessions(): array
    {
        $configuration = (new ConfigurationFactory())->create(Environment::fromGlobals())->database;
        $factory = new DoctrineConnectionFactory($configuration);
        $other = new DatabaseConfiguration(
            $configuration->driver,
            $configuration->host,
            $configuration->port,
            $configuration->driver === 'pgsql' ? 'postgres' : 'information_schema',
            $configuration->user,
            $configuration->password,
            $configuration->tablePrefix,
            $configuration->sslMode,
            $configuration->serverVersion,
        );

        return [
            $factory->create(),
            $factory->create(),
            (new DoctrineConnectionFactory($other))->create(),
            $configuration->tablePrefix,
        ];
    }

    /**
     * Call a private lock-identity method.
     *
     * @param   object       $subject    Lock owner.
     * @param   string       $method     Private method name.
     * @param   list<mixed>  $arguments  Arguments.
     *
     * @return  mixed  Whatever the method returned.
     *
     * @since   2.0.0
     */
    private function invoke(object $subject, string $method, array $arguments): mixed
    {
        return (new ReflectionMethod($subject, $method))->invokeArgs($subject, $arguments);
    }
}
