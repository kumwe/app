<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Prevents extension field presenters from becoming a transport, persistence, or template escape hatch.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class FieldPresenterBoundaryTest extends TestCase
{
    /**
     * The extension-facing presentation contract contains only typed metadata and bounded semantic output.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPresenterContractsContainNoTransportPersistenceOrContainerDependencies(): void
    {
        $contracts = '';
        foreach (
            [
                'FieldPresenter.php',
                'FieldPresentation.php',
                'FieldPresentationContext.php',
                'FieldPresentationCoverage.php',
                'FieldPresentationRequest.php',
                'FieldWidget.php',
            ] as $file
        ) {
            $contracts .= $this->source('src/BusinessSurface/Presentation/Field/' . $file);
        }

        foreach (
            [
                'Psr\\Http',
                'ServerRequestInterface',
                'Doctrine\\DBAL',
                'Connection',
                'Repository',
                'ContainerInterface',
                'Joomla\\DI',
                'callable',
                'Closure',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $contracts);
        }
        self::assertStringContainsString(
            'present(FieldPresentationRequest $request): FieldPresentation',
            $contracts,
        );
    }

    /**
     * Container composition shares the presenter registry populated through extension contributions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompositionUsesTheContributionOwnedRegistry(): void
    {
        $container = $this->source('src/Kernel/ContainerFactory.php');

        self::assertStringContainsString(
            'self::service($container, ExtensionContributionRegistrySet::class)->fieldPresentations()',
            $container,
        );
        self::assertStringNotContainsString('new FieldPresentationRegistry()', $container);
    }

    /**
     * Read one repository source file for a dependency-boundary assertion.
     *
     * @param   string  $path  Repository-relative source path.
     *
     * @return  string  Complete source bytes.
     *
     * @since   2.0.0
     */
    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source, 'Could not read ' . $path . '.');

        return $source;
    }
}
