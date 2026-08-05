<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Persistence;

use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationRunner;
use Kumwe\CMS\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\JobRecoveryMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DoctrineMigrationLock;
use Kumwe\CMS\Infrastructure\Persistence\Migration\DoctrineMigrationRepository;
use Kumwe\CMS\Infrastructure\Persistence\ReadinessProbe;
use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationRunner::class)]
#[CoversClass(DoctrineMigrationLock::class)]
#[CoversClass(DoctrineMigrationRepository::class)]
#[CoversClass(CoreSchemaMigration::class)]
#[CoversClass(JobRecoveryMigration::class)]
final class MigrationIntegrationTest extends TestCase
{
    public function testDatabaseMigrationIsIdempotentAndReady(): void
    {
        $container = (new ContainerFactory())->create(Environment::fromGlobals());
        $runner = $container->get(MigrationRunner::class);

        self::assertFalse($runner->migrate()->changed());
        self::assertTrue($container->get(ReadinessProbe::class)->ready());
    }
}
