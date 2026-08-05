<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class TemplateBoundaryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testPublicHandlersCannotDependOnTwigOrAdministratorRendering(): void
    {
        foreach (['src/Http/Handler/HomePageHandler.php', 'src/Http/Handler/PublishedContentHandler.php'] as $file) {
            $source = $this->contents($file);
            self::assertStringContainsString('SiteRenderer', $source);
            self::assertStringNotContainsString('Twig\\Environment', $source);
            self::assertStringNotContainsString('AdministratorRenderer', $source);
        }
    }

    public function testCoreAdministratorPagesUseTheSurfaceOverridableLayout(): void
    {
        $templates = glob($this->root . '/templates/administrator/*.twig');
        self::assertIsArray($templates);
        self::assertNotEmpty($templates);

        foreach ($templates as $file) {
            if (basename($file) === 'layout.twig') {
                continue;
            }
            $source = file_get_contents($file);
            self::assertIsString($source);
            self::assertStringContainsString('{% extends "layout.twig" %}', $source, $file);
        }
    }

    public function testAppliedCoreMigrationWasNotModifiedForThemeState(): void
    {
        $core = $this->contents('src/Infrastructure/Persistence/Migration/CoreSchemaMigration.php');
        $forward = $this->contents(
            'src/Infrastructure/Persistence/Migration/IsolateThemeSurfacesMigration.php',
        );

        self::assertStringNotContainsString('theme_activations', $core);
        self::assertStringContainsString('theme_activations', $forward);
        self::assertStringContainsString('site_theme_activations', $forward);
        self::assertStringContainsString('themes.site.manage', $forward);
        self::assertStringContainsString('themes.administrator.manage', $forward);
        self::assertStringContainsString('extension_runtime_publications', $forward);
        self::assertStringContainsString('extension_runtime_materializations', $forward);
        self::assertStringContainsString('extension_runtime_retirements', $forward);
        self::assertStringContainsString('extension_install_operations', $forward);
        self::assertStringContainsString('transaction_outcome', $forward);
    }

    public function testRecoveryEnvironmentReceivesNoExtensionOrThemePaths(): void
    {
        $factory = $this->contents('src/Presentation/Twig/IsolatedTwigEnvironmentFactory.php');
        $method = substr($factory, (int) strpos($factory, 'public function recoveryAdministrator'));
        $method = substr($method, 0, (int) strpos($method, 'private function surfaceLoader'));

        self::assertStringContainsString("'core-admin'", $method);
        self::assertStringNotContainsString('themePath(', $method);
        self::assertStringNotContainsString('extensionViewPaths(', $method);
    }

    public function testAdministratorThemeLoaderExposesOnlyTheExplicitShellContract(): void
    {
        $factory = $this->contents('src/Presentation/Twig/IsolatedTwigEnvironmentFactory.php');
        self::assertStringContainsString(
            "new ContractRestrictedLoader(\$theme, ['layout.twig', '@admin-theme/layout.twig'])",
            $factory,
        );
        self::assertStringNotContainsString("\$core->addPath(\$themePath)", $factory);
    }

    public function testInstallationIsDisabledAndRuntimeMapCarriesSurfaceAssignments(): void
    {
        $manager = $this->contents('src/Extension/Infrastructure/DoctrineExtensionManager.php');
        $compiler = $this->contents('src/Extension/Runtime/ExtensionRuntimeMapCompiler.php');
        $loader = $this->contents('src/Extension/Runtime/ExtensionRuntimeLoader.php');

        self::assertStringContainsString("'status' => 'disabled'", $manager);
        self::assertStringNotContainsString("'status' => 'active'", $manager);
        self::assertStringContainsString("status = 'disabled'", $manager);
        self::assertStringContainsString("'theme_surfaces' =>", $compiler);
        self::assertStringContainsString('$type !== \'template\'', $loader);
        self::assertStringNotContainsString('file_get_contents', $loader);
        self::assertStringContainsString('VerifiedRuntimePublication', $loader);
        self::assertStringContainsString("'runtime_tree_sha256' =>", $compiler);
        self::assertStringContainsString("'asset_tree_sha256' =>", $compiler);
        self::assertStringNotContainsString('materializeLatest(', $manager);
        self::assertStringNotContainsString('recoverAdministratorTheme', $manager);
    }

    public function testRequestContainerNeverMaterializesRuntimeState(): void
    {
        $container = $this->contents('src/Kernel/ContainerFactory.php');
        $extensions = substr($container, (int) strpos($container, 'private function registerExtensions'));
        $extensions = substr($extensions, 0, (int) strpos($extensions, 'private function registerMiddleware'));

        self::assertStringContainsString('inspectLocal()', $extensions);
        self::assertStringNotContainsString('matchesAuthority(', $extensions);
        self::assertStringNotContainsString('reconcileAndMaterialize()', $extensions);
    }

    public function testRecoveryAndConcreteRegistryManagerAreConsoleOnly(): void
    {
        $container = $this->contents('src/Kernel/ContainerFactory.php');
        $extensions = substr($container, (int) strpos($container, 'private function registerExtensions'));
        $extensions = substr($extensions, 0, (int) strpos($extensions, 'private function registerMiddleware'));

        self::assertStringNotContainsString('share(DoctrineExtensionManager::class', $extensions);
        self::assertStringNotContainsString('DoctrineAdministratorThemeRecovery', $extensions);
        self::assertStringContainsString('if ($console)', $container);
        self::assertStringContainsString('bootstrap/console.php', $this->contents('bin/kumwe'));
    }

    public function testThemeMutationRecheckLocksTheCurrentGrantWithinTheRegistryTransaction(): void
    {
        $authorization = $this->contents(
            'src/Presentation/Infrastructure/DoctrineThemeMutationAuthorizer.php',
        );

        self::assertStringContainsString('isTransactionActive()', $authorization);
        self::assertStringContainsString('FOR UPDATE', $authorization);
        self::assertStringContainsString("u.status = 'active'", $authorization);
    }

    private function contents(string $path): string
    {
        $source = file_get_contents($this->root . '/' . $path);
        self::assertIsString($source, sprintf('Could not read %s.', $path));

        return $source;
    }
}
