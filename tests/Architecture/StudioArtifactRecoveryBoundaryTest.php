<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Keeps AP-4 persistence behind neutral Studio application ports and one composition root.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class StudioArtifactRecoveryBoundaryTest extends TestCase
{
    /**
     * Artifact and recovery application policy cannot import product domains or persistence drivers.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testArtifactAndRecoveryApplicationPortsRemainNeutralAndDriverFree(): void
    {
        $application = $this->source('src/Studio/Application/Host');
        $artifactDomain = $this->source('src/Studio/Domain/Artifact');

        foreach ([$application, $artifactDomain] as $source) {
            self::assertStringNotContainsString('Kumwe\\App\\Content\\', $source);
            self::assertStringNotContainsString('Kumwe\\App\\BusinessRecord\\', $source);
            self::assertStringNotContainsString('Kumwe\\App\\BusinessDefinition\\', $source);
            self::assertStringNotContainsString('Kumwe\\App\\Extension\\', $source);
            self::assertStringNotContainsString('Doctrine\\DBAL\\', $source);
        }
    }

    /**
     * Producer receives complete direct ports behind the App's fresh request authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProducerHostExposesCompletePortsBehindFreshRequestAuthority(): void
    {
        $host = $this->contents('src/Studio/Application/Host/StudioProducerHost.php');
        $factory = $this->contents('src/Studio/Application/Host/StudioProducerHostFactory.php');
        $authority = $this->contents('src/Studio/Application/Host/StudioProducerRequestAuthority.php');
        $artifact = $this->contents('src/Studio/Application/Host/StudioArtifactHostPort.php');
        $recovery = $this->contents('src/Studio/Application/Host/StudioRecoveryHostPort.php');

        self::assertStringContainsString('implements HostAdapterInterface', $host);
        self::assertStringContainsString('new StudioProducerRequestAuthority', $factory);
        self::assertStringContainsString('studio.host/stale-session-generation', $authority);
        foreach (['dependencies', 'load', 'publish', 'save', 'unpublish'] as $operation) {
            self::assertStringContainsString('function ' . $operation . '(', $artifact);
        }
        foreach (['discard', 'load', 'store'] as $operation) {
            self::assertStringContainsString('function ' . $operation . '(', $recovery);
        }
        self::assertSame(2, substr_count($artifact, '$this->requireExpectedRevision($context)'));
        self::assertStringContainsString('$this->setPublished($arguments, $context, true)', $artifact);
        self::assertStringContainsString('$this->setPublished($arguments, $context, false)', $artifact);
    }

    /**
     * Doctrine storage is one infrastructure adapter and only ContainerFactory constructs it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPersistenceAdapterIsConstructedOnlyByTheCompositionRoot(): void
    {
        $adapter = $this->contents('src/Studio/Infrastructure/Persistence/DoctrineStudioHostStorage.php');
        $container = $this->contents('src/Kernel/ContainerFactory.php');

        self::assertStringContainsString('implements', $adapter);
        self::assertStringContainsString('StudioArtifactRepository', $adapter);
        self::assertStringContainsString('StudioMutationReplayRepository', $adapter);
        self::assertStringContainsString('StudioRecoveryRepository', $adapter);
        self::assertSame(1, substr_count($container, 'new DoctrineStudioHostStorage'));
        self::assertStringNotContainsString('new DoctrineStudioHostStorage', $this->source('src/Studio'));
    }

    /**
     * Concatenate PHP sources beneath one repository-relative directory.
     *
     * @param   string  $path  Repository-relative source directory.
     *
     * @return  string  Concatenated source bytes.
     *
     * @since   2.0.0
     */
    private function source(string $path): string
    {
        $directory = dirname(__DIR__, 2) . '/' . $path;
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
     * @return  string  Exact file bytes.
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
