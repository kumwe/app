<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the demo provenance schema in the immutable core migration path.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class DemoProfilePersistenceArchitectureTest extends TestCase
{
    /**
     * Proves the repeatable source-bound migration is registered after every previously shipped migration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDemoProfileProvenanceMigrationIsRegisteredLast(): void
    {
        $root = dirname(__DIR__, 2);
        $container = file_get_contents($root . '/src/Kernel/ContainerFactory.php');
        $migration = file_get_contents(
            $root . '/src/Infrastructure/Persistence/Migration/DemoProfileProvenanceMigration.php',
        );

        self::assertIsString($container);
        self::assertIsString($migration);
        self::assertStringContainsString('implements RepeatableMigration', $migration);
        self::assertStringContainsString("hash_file('sha256', __FILE__)", $migration);
        self::assertStringContainsString("raw('demo_profile_installations')", $migration);
        self::assertStringContainsString("raw('demo_profile_assets')", $migration);
        $previous = strpos($container, 'new BusinessIntegrationSdkMigration(');
        $provenance = strpos($container, 'new DemoProfileProvenanceMigration(');
        self::assertNotFalse($previous);
        self::assertNotFalse($provenance);
        self::assertGreaterThan($previous, $provenance);
    }
}
