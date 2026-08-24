<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Holds the Studio model port to a neutral contract core and parallel Content and Business adapters.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class StudioContentProjectionBoundaryTest extends TestCase
{
    /**
     * The neutral Studio contract interpreter owns no Extension, Content or Business concept.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStudioContractDomainIsNeutralAcrossHostBoundedContexts(): void
    {
        $contract = $this->source('src/Studio/Domain/Contract');

        self::assertStringNotContainsString('Kumwe\\App\\Extension\\', $contract);
        self::assertStringNotContainsString('Kumwe\\App\\Content\\', $contract);
        self::assertStringNotContainsString('Kumwe\\App\\BusinessRecord\\', $contract);
        self::assertStringNotContainsString('Doctrine\\DBAL\\', $contract);
    }

    /**
     * Content and BusinessRecord remain parallel products and the Content adapter imports no business runtime.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContentProjectionCannotBecomeABusinessRecordShortcut(): void
    {
        $contentProjection = $this->contents(
            'src/Studio/Application/Projection/ContentStudioProjector.php',
        ) . $this->contents(
            'src/Studio/Application/Projection/StudioContentProjectionService.php',
        ) . $this->contents(
            'src/Studio/Application/Projection/StudioContentFieldDisclosure.php',
        );

        self::assertStringNotContainsString('Kumwe\\App\\BusinessRecord\\', $contentProjection);
        self::assertStringNotContainsString('Kumwe\\App\\BusinessDefinition\\', $contentProjection);
        self::assertStringNotContainsString('Kumwe\\App\\BusinessRecord\\', $this->source('src/Content'));
        self::assertStringNotContainsString('Kumwe\\App\\Content\\', $this->source('src/BusinessRecord'));
    }

    /**
     * AP-2 exposes authorized reads only and leaves persistence writes and authoring sessions to later phases.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProjectionApplicationBoundaryIsReadOnlyAndDriverFree(): void
    {
        $application = $this->source('src/Studio/Application/Projection');
        $repository = $this->contents(
            'src/Studio/Application/Projection/ContentProjectionBindingRepository.php',
        );

        self::assertStringNotContainsString('Doctrine\\DBAL\\', $application);
        self::assertDoesNotMatchRegularExpression(
            '/public\s+function\s+(?:save|store|insert|update|delete|publish|write|beginSession)\b/',
            $repository,
        );
        self::assertDoesNotMatchRegularExpression(
            '/->(?:executeStatement|insert|update|delete)\s*\(/',
            $application,
        );
        self::assertStringNotContainsString('StudioSession', $application);
    }

    /**
     * Read every PHP source file beneath one repository-relative directory.
     *
     * @param   string  $path  Repository-relative source directory.
     *
     * @return  string  Concatenated PHP source, or an empty string when the directory is absent.
     *
     * @since   2.0.0
     */
    private function source(string $path): string
    {
        $directory = dirname(__DIR__, 2) . '/' . $path;
        if (!is_dir($directory)) {
            return '';
        }
        $source = '';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $contents = file_get_contents($file->getPathname());
                self::assertIsString($contents);
                $source .= $contents;
            }
        }

        return $source;
    }

    /**
     * Read one required repository-relative source file.
     *
     * @param   string  $path  Repository-relative source path.
     *
     * @return  string  File bytes.
     *
     * @since   2.0.0
     */
    private function contents(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents, sprintf('Could not read %s.', $path));

        return $contents;
    }
}
