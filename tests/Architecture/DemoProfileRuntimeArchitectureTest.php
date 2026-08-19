<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins demo reconciliation to the deployment boundary and its purpose-specific principal.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class DemoProfileRuntimeArchitectureTest extends TestCase
{
    /**
     * Proves schema work completes before profiles and runtime publication consumes the reconciled state.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMigrationCommandOrdersSchemaProfilesAndRuntimeMaterialization(): void
    {
        $source = $this->contents('src/Delivery/Console/Command/MigrateCommand.php');
        $schema = strpos($source, '$this->runner->migrate(');
        $profiles = strpos($source, '$this->profiles->reconcile()');
        $runtime = strpos($source, '$this->extensions->reconcileAndMaterialize(true)');

        self::assertNotFalse($schema);
        self::assertNotFalse($profiles);
        self::assertNotFalse($runtime);
        self::assertGreaterThan($schema, $profiles);
        self::assertGreaterThan($profiles, $runtime);
    }

    /**
     * Proves the composition root does not reuse schema-migration authority for ordinary fixture resources.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompositionRootIssuesASeparateProfileInstallerPrincipal(): void
    {
        $source = $this->contents('src/Kernel/ContainerFactory.php');
        $installer = strpos($source, 'SystemIdentity::ProfileInstaller');
        $migration = strpos($source, 'SystemIdentity::Migration', $installer === false ? 0 : $installer);

        self::assertNotFalse($installer);
        self::assertNotFalse($migration);
        self::assertStringContainsString(
            'SystemPrincipal::issue($provenance, SystemIdentity::ProfileInstaller)',
            $source,
        );
        self::assertStringContainsString(
            'SystemPrincipal::issue($provenance, SystemIdentity::Migration)',
            $source,
        );
    }

    /**
     * Read one repository file while retaining a useful assertion failure for unavailable sources.
     *
     * @param   string  $path  Repository-relative file path.
     *
     * @return  string  Complete source bytes.
     *
     * @since   2.0.0
     */
    private function contents(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents, sprintf('Unable to read %s.', $path));

        return $contents;
    }
}
