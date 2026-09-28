<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Extension;

use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Package\NonConformingPackage;
use Kumwe\App\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Extension\Package\InvalidPackage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use ZipArchive;

/**
 * Pins that an extension archive cannot write outside its own deployment through a crafted entry.
 *
 * Each archive is an otherwise valid package carrying one hostile entry: a parent-directory escape, an
 * absolute path, a backslash escape that a Windows-style extractor would honour, a nested escape hidden
 * behind a real directory, or a symbolic link to a host file. The production extension manager must
 * refuse the whole package, install nothing, and leave no file under any directory the escape could name.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineExtensionManager::class)]
final class ExtensionArchiveTraversalIntegrationTest extends TestCase
{
    /**
     * Every hostile entry shape refuses the whole package and writes nothing outside it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAHostileArchiveEntryRefusesTheWholePackageAndEscapesNowhere(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $extensions = $container->get(ExtensionManager::class);
        self::assertInstanceOf(ExtensionManager::class, $extensions);
        $context = TestKernelFactory::administratorContext($container);
        $root = dirname(__DIR__, 3);

        foreach (['parent', 'absolute', 'backslash', 'nested', 'symlink'] as $shape) {
            $marker = 'escape-' . $shape . '-' . bin2hex(random_bytes(6));
            $identifier = 'integration/traversal-' . Uuid::uuid7()->toString();
            $archive = $this->package($identifier, $shape, $marker);
            try {
                try {
                    $extensions->install($archive, $context);
                    self::fail(sprintf('The %s entry must refuse the package.', $shape));
                } catch (InvalidPackage | NonConformingPackage $refusal) {
                    self::assertMatchesRegularExpression('/unsafe entry path|symbolic link/', $refusal->getMessage());
                }
                $installed = array_column($extensions->installed($context), 'identifier');
                self::assertNotContains($identifier, $installed, $shape);
                foreach ([$root, $root . '/extensions', $root . '/storage', sys_get_temp_dir(), '/tmp'] as $directory) {
                    self::assertFileDoesNotExist($directory . '/' . $marker . '.php', $shape . ' in ' . $directory);
                }
                self::assertSame([], $this->find($root . '/storage', $marker), $shape . ' anywhere under storage');
                self::assertSame([], $this->find($root . '/extensions', $marker), $shape . ' anywhere in extensions');
            } finally {
                unlink($archive);
            }
        }
    }

    /**
     * Build a valid package plus one hostile entry of the requested shape.
     *
     * @param   string  $identifier  Package identifier.
     * @param   string  $shape       Which hostile entry to add.
     * @param   string  $marker      Unique file stem the hostile entry tries to create.
     *
     * @return  string  Path of the written archive.
     *
     * @throws  RuntimeException  When the archive cannot be written.
     *
     * @since   2.0.0
     */
    private function package(string $identifier, string $shape, string $marker): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'kumwe-traversal-extension-');
        $zip = new ZipArchive();
        if (!is_string($archive) || $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The traversal archive could not be opened.');
        }
        $manifest = json_encode([
            'schema' => 1,
            'name' => $identifier,
            'type' => 'plugin',
            'version' => '1.0.0',
            'provider' => 'KumweIntegration\\Traversal\\Provider',
            'autoload' => ['psr-4' => ['KumweIntegration\\Traversal\\' => 'src/']],
            'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
            'dependencies' => [],
            'migrations' => [],
            'configuration' => [],
            'permissions' => [],
            'routes' => [],
            'events' => [],
            'assets' => [],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $payload = "<?php\n\nfile_put_contents('/tmp/" . $marker . ".txt', 'owned');\n";
        $zip->addFromString('kumwe.json', $manifest);
        $zip->addFromString('src/Provider.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace KumweIntegration\Traversal;

use Kumwe\Extension\Spi\Runtime\BootableExtension;
use Kumwe\Extension\Spi\Runtime\ExtensionContainer;

final class Provider implements BootableExtension
{
    public function register(ExtensionContainer $container): void
    {
    }

    public function boot(ExtensionContainer $container): void
    {
    }
}
PHP);
        match ($shape) {
            'parent' => $zip->addFromString('../../../' . $marker . '.php', $payload),
            'absolute' => $zip->addFromString('/tmp/' . $marker . '.php', $payload),
            'backslash' => $zip->addFromString('src\\..\\..\\..\\' . $marker . '.php', $payload),
            'nested' => $zip->addFromString('src/deep/../../../../' . $marker . '.php', $payload),
            default => $zip->addFromString('src/' . $marker . '.php', '/etc/passwd'),
        };
        if ($shape === 'symlink') {
            $zip->setExternalAttributesName('src/' . $marker . '.php', ZipArchive::OPSYS_UNIX, (0o120777 << 16));
        }
        $zip->close();

        return $archive;
    }

    /**
     * Find every file under a directory whose name contains the marker.
     *
     * @param   string  $directory  Directory to search; absent directories hold nothing.
     * @param   string  $marker     Unique file stem.
     *
     * @return  list<string>  Matching paths.
     *
     * @since   2.0.0
     */
    private function find(string $directory, string $marker): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $found = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && str_contains($file->getFilename(), $marker)) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }
}
