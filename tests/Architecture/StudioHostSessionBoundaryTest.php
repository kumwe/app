<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Keeps the Studio host authority additive, trusted-context-only and independent of product adapters.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class StudioHostSessionBoundaryTest extends TestCase
{
    /**
     * Host authority imports only shared authorization and neutral Studio contract concepts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHostAuthorityDoesNotImportContentBusinessOrStudioEngineImplementation(): void
    {
        $host = $this->source('src/Studio/Application/Host');

        self::assertStringNotContainsString('Kumwe\\App\\Content\\', $host);
        self::assertStringNotContainsString('Kumwe\\App\\BusinessRecord\\', $host);
        self::assertStringNotContainsString('Kumwe\\App\\BusinessDefinition\\', $host);
        self::assertStringNotContainsString('Kumwe\\App\\Extension\\', $host);
        self::assertStringNotContainsString('Doctrine\\DBAL\\', $host);
        self::assertStringNotContainsString('@kumwe/studio-core', $host);
    }

    /**
     * The envelope decoder has no path for client-supplied identity or capability evidence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTrustedIdentityIsReadOnlyFromExecutionContextAndNeverDecodedFromHostJson(): void
    {
        $decoder = $this->contents('src/Studio/Application/Host/StudioHostRequestDecoder.php');
        $authority = $this->contents('src/Studio/Application/Host/StudioHostSessionAuthority.php');

        self::assertStringNotContainsString('actorId', $decoder);
        self::assertStringNotContainsString('capabilities', $decoder);
        self::assertStringContainsString('$context->actorId()', $authority);
        self::assertStringContainsString('$context->site()->identifier()', $authority);
        self::assertStringContainsString('$context->approvalFingerprint()', $authority);
        self::assertStringContainsString('AuthorizationGateway', $authority);
    }

    /**
     * One normative route and dispatcher fence every operation; AP-5 adds only the media port.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryPortRouteHasOneDispatcherAndImplementedPortsRemainCanonical(): void
    {
        $container = $this->contents('src/Kernel/ContainerFactory.php');
        $dispatcher = $this->contents('src/Studio/Application/Host/StudioHostDispatcher.php');

        self::assertSame(1, substr_count($container, "'/administrator/studio/ports/{port}/{operation}'"));
        self::assertStringContainsString('studio.operation/permission.explain', $dispatcher);
        self::assertStringContainsString('studio.operation/permission.refresh', $dispatcher);
        self::assertStringContainsString('studio.operation/media.authorize-upload', $dispatcher);
        self::assertStringContainsString('studio.operation/media.import-external', $dispatcher);
        self::assertStringContainsString('studio.host/stale-session-generation', $dispatcher);
        self::assertStringContainsString('studio.host/operation-unavailable', $dispatcher);
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
        self::assertIsString($contents);

        return $contents;
    }
}
