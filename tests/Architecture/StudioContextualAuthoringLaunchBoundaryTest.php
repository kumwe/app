<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the fail-closed production composition of contextual Content Studio launches.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class StudioContextualAuthoringLaunchBoundaryTest extends TestCase
{
    /**
     * Production supplies an unavailable configuration provider independently of runtime evidence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProductionRequiresTheAtomicLaunchResolverAndUnavailableConfigurationProvider(): void
    {
        $container = $this->contents('src/Kernel/ContainerFactory.php');
        $handler = $this->contents('src/Administrator/Http/Handler/AdministratorContentEditorHandler.php');

        self::assertStringContainsString(
            'StudioContextualAuthoringConfigurationProvider::class,' . "\n"
                . '            new UnavailableStudioContextualAuthoringConfigurationProvider(),',
            $container,
        );
        self::assertStringContainsString('ContentStudioAuthoringLaunchResolver::class', $container);
        self::assertStringContainsString(
            'self::service($container, StudioContextualAuthoringConfigurationProvider::class)',
            $container,
        );
        self::assertStringContainsString(
            'self::service($container, ContentStudioAuthoringLaunchResolver::class)',
            $container,
        );
        self::assertStringContainsString('private ContentStudioAuthoringLaunchResolver $studioLaunches', $handler);
        self::assertStringNotContainsString('private StudioContextualAuthoringAvailability', $handler);
        self::assertStringContainsString('$this->studioLaunches->resolve(', $handler);
        self::assertStringContainsString('$session->csrfToken', $handler);
    }

    /**
     * App retains the future configuration as an opaque object and does not define Studio's wire shape.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConfigurationSeamDoesNotInventOrSerializeAStudioContract(): void
    {
        $configuration = $this->contents(
            'src/Studio/Application/Authoring/StudioContextualAuthoringConfiguration.php',
        );
        $launch = $this->contents('src/Studio/Application/Authoring/ContentStudioAuthoringLaunch.php');
        $target = $this->contents('src/Studio/Application/Authoring/ContentStudioAuthoringTarget.php');

        self::assertStringNotContainsString('function toArray', $configuration);
        self::assertStringNotContainsString('json_encode', $configuration);
        self::assertStringNotContainsString('json_encode', $launch);
        self::assertStringContainsString("'configuration' => \$this->configuration", $launch);
        self::assertStringNotContainsString('Configuration', $target);
    }

    /**
     * Read one repository file or fail with its relative path.
     *
     * @param   string  $path  Path below the application root.
     *
     * @return  string  Exact file contents.
     *
     * @since   2.0.0
     */
    private function contents(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents, $path);

        return $contents;
    }
}
