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
     * Direct Producer authorization has no path for client-supplied identity or capability evidence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTrustedIdentityComesOnlyFromExecutionContextAndLiveSessionAuthority(): void
    {
        $requestAuthority = $this->contents('src/Studio/Application/Host/StudioProducerRequestAuthority.php');
        $authority = $this->contents('src/Studio/Application/Host/StudioHostSessionAuthority.php');

        self::assertStringContainsString('private readonly ExecutionContext $context', $requestAuthority);
        self::assertStringContainsString('$this->sessions->resolve(', $requestAuthority);
        self::assertStringNotContainsString('actorId', $requestAuthority);
        self::assertStringNotContainsString('capabilities', $requestAuthority);
        self::assertStringContainsString('$context->actorId()', $authority);
        self::assertStringContainsString('$context->site()->identifier()', $authority);
        self::assertStringContainsString('$context->approvalFingerprint()', $authority);
        self::assertStringContainsString('AuthorizationGateway', $authority);
    }

    /**
     * One normative route feeds Producer's complete host and every implemented canonical port.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryPortRouteUsesOneCompleteProducerHost(): void
    {
        $container = $this->contents('src/Kernel/ContainerFactory.php');
        $host = $this->contents('src/Studio/Application/Host/StudioProducerHost.php');
        $handler = $this->contents('src/Administrator/Http/Handler/AdministratorStudioHostHandler.php');

        self::assertSame(1, substr_count($container, "'/administrator/studio/ports/{port}/{operation}'"));
        self::assertStringContainsString('new Dispatcher(', $handler);
        self::assertStringContainsString('implements HostAdapterInterface', $host);
        $ports = [
            'artifact',
            'localization',
            'media',
            'model',
            'permission',
            'preview',
            'recovery',
            'resource',
            'telemetry',
        ];
        foreach ($ports as $port) {
            self::assertStringContainsString('function ' . $port . '()', $host);
        }
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
