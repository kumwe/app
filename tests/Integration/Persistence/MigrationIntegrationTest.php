<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Persistence;

use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationRunner;
use Kumwe\CMS\Infrastructure\Persistence\Migration\PostgreSqlMigrationLock;
use Kumwe\CMS\Infrastructure\Persistence\Migration\PostgreSqlMigrationRepository;
use Kumwe\CMS\Infrastructure\Persistence\Migration\Version202608040001CreateSystemTables;
use Kumwe\CMS\Infrastructure\Persistence\ReadinessProbe;
use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationRunner::class)]
#[CoversClass(PostgreSqlMigrationLock::class)]
#[CoversClass(PostgreSqlMigrationRepository::class)]
#[CoversClass(Version202608040001CreateSystemTables::class)]
final class MigrationIntegrationTest extends TestCase
{
    public function testCleanPostgreSqlDatabaseMigratesAndBecomesReady(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $runner = $container->get(MigrationRunner::class);

        self::assertTrue($runner->migrate()->changed());
        self::assertFalse($runner->migrate()->changed());
        self::assertTrue($container->get(ReadinessProbe::class)->ready());
    }
}
