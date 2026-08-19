<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversNothing]
/**
 * Protects the KIS semantic contract from delivery, persistence, and parallel-registry coupling.
 *
 * @since  2.0.0
 */
final class InterfaceStandardBoundaryTest extends TestCase
{
    /**
     * Semantic declarations reuse the contribution contract and introduce no competing registry or container.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSemanticDefinitionsReuseTheExistingContributionBoundary(): void
    {
        $root = dirname(__DIR__, 2);
        $definition = $this->contents($root . '/src/InterfaceStandard/SurfaceDefinition.php');

        self::assertStringContainsString('implements ContributionDefinition', $definition);
        self::assertStringNotContainsString('class SurfaceRegistry', $definition);
        self::assertStringNotContainsString('ContainerFactory', $definition);
        foreach (glob($root . '/src/InterfaceStandard/*Registry.php') ?: [] as $registry) {
            self::fail(sprintf('KIS must reuse the existing contribution registries, found %s.', $registry));
        }
    }

    /**
     * The bounded contract remains transport-free and cannot embed executable presentation or persistence APIs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSemanticContractHasNoFrameworkOrExecutableDeliveryDependency(): void
    {
        $root = dirname(__DIR__, 2) . '/src/InterfaceStandard';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = $this->contents($file->getPathname());
            foreach (['Doctrine\\', 'Twig\\', 'Laminas\\', 'Mezzio\\', 'Psr\\Http\\', 'Joomla\\DI\\'] as $namespace) {
                self::assertStringNotContainsString(
                    sprintf('use %s', $namespace),
                    $source,
                    $file->getPathname(),
                );
            }
            self::assertDoesNotMatchRegularExpression(
                '/(?:eval|include|require|shell_exec|proc_open)\s*\(/',
                $source,
                $file->getPathname(),
            );
        }
    }

    /**
     * Read a source file for an architecture assertion.
     *
     * @param   string  $path  Absolute source path.
     *
     * @return  string  File contents.
     *
     * @since   2.0.0
     */
    private function contents(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, sprintf('Could not read %s.', $path));
        return $contents;
    }
}
