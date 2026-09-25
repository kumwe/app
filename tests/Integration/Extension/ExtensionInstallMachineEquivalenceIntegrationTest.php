<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Extension;

use Doctrine\DBAL\Connection;
use Kumwe\App\Delivery\Console\Command\InstallExtensionCommand;
use Kumwe\App\Delivery\Http\Api\Extension\ExtensionApiHandler;
use Kumwe\App\Delivery\Http\Api\Idempotency\HttpMutationPreauthorizer;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\MachineSurfaceHarness;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Proves an agent installs an extension package through REST exactly as the extensions screen and the console do.
 *
 * REST posts the package ZIP as the raw body of `POST /api/v1/extensions` and the console reads it from a path;
 * both reach `ExtensionManager::install()`, the call the screen's upload makes, so each package lands disabled at
 * its manifest version with one `extension.install` audit event, and the serving process then drains until the
 * runtime is reloaded. A credential without `extensions.manage`, a half-supplied signature and a file that is not
 * a package are refused on both surfaces. MCP deliberately offers no install: adding executable code stays
 * outside the MCP authority boundary. The same assertions run on MariaDB and PostgreSQL.
 *
 * @since  2.0.0
 */
#[CoversClass(ExtensionApiHandler::class)]
#[CoversClass(InstallExtensionCommand::class)]
#[CoversClass(HttpMutationPreauthorizer::class)]
final class ExtensionInstallMachineEquivalenceIntegrationTest extends TestCase
{
    /**
     * Kernel of the running test.
     *
     * @var    ?Container
     * @since  2.0.0
     */
    private ?Container $container = null;

    /**
     * Harnesses of the running test, one per booted kernel.
     *
     * @var    list<MachineSurfaceHarness>
     * @since  2.0.0
     */
    private array $harnesses = [];

    /**
     * Extensions the running test installed, uninstalled afterwards.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $installed = [];

    /**
     * Package archives the running test wrote.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $archives = [];

    /**
     * Uninstall every package and remove every archive and token the running test created.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        if ($this->container !== null) {
            $extensions = $this->container->get(ExtensionManager::class);
            self::assertInstanceOf(ExtensionManager::class, $extensions);
            foreach ($this->installed as $identifier) {
                $extensions->uninstall($identifier, TestKernelFactory::administratorContext($this->container));
            }
        }
        foreach ($this->archives as $archive) {
            if (is_file($archive)) {
                unlink($archive);
            }
        }
        foreach ($this->harnesses as $harness) {
            $harness->cleanup();
        }
        $this->harnesses = [];
        $this->container = null;
        $this->installed = [];
        $this->archives = [];
    }

    /**
     * REST and the console install a package to the same disabled registry row and one audit event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRestAndTheConsoleInstallLikeTheExtensionsScreen(): void
    {
        $traces = [];
        foreach (['rest', 'cli'] as $surface) {
            [$container, $harness] = $this->boot();
            $token = $harness->token($surface, ['extensions.manage']);
            $identifier = 'parity/install-' . $surface . '-' . bin2hex(random_bytes(4));
            $archive = $this->package($identifier);
            if ($surface === 'rest') {
                $first = $harness->rest($token, 'POST', '/api/v1/extensions', self::bytes($archive), [
                    'Idempotency-Key' => 'extension-install-parity-' . bin2hex(random_bytes(6)),
                ]);
                $this->installed[] = $identifier;
                self::assertSame(201, $first['status'], $first['raw']);
                self::assertSame('no-store', $first['headers']['cache-control'] ?? null);
                self::assertIsArray($first['body']);
                self::assertSame($identifier, $first['body']['identifier']);
                self::assertSame($first['body']['status'], self::row($container, $identifier)['status'] ?? null);
                // The install changed the extension runtime, so this process drains until it is reloaded.
                $drained = $harness->rest($token, 'GET', '/api/v1/extensions');
                self::assertSame(503, $drained['status'], $drained['raw']);
            } else {
                $run = $harness->cli(InstallExtensionCommand::class, $token, [$archive]);
                $this->installed[] = $identifier;
                self::assertSame(0, $run['status'], $run['stderr']);
            }
            $row = self::row($container, $identifier);
            $traces[$surface] = [
                'status' => $row['status'] ?? null,
                'version' => $row['installed_version'] ?? null,
                'type' => $row['extension_type'] ?? null,
                'audit' => self::installEvents($container, $identifier),
            ];
        }

        self::assertSame(
            ['status' => 'disabled', 'version' => '1.0.0', 'type' => 'plugin', 'audit' => 1],
            $traces['rest'],
        );
        self::assertSame($traces['rest'], $traces['cli']);
    }

    /**
     * A missing grant, a half signature and a file that is not a package are refused on both surfaces.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsMatchOnBothSurfaces(): void
    {
        [$container, $harness] = $this->boot();
        $identifier = 'parity/install-refused-' . bin2hex(random_bytes(4));
        $archive = $this->package($identifier);
        $junk = tempnam(sys_get_temp_dir(), 'kumwe-parity-junk-');
        self::assertIsString($junk);
        $this->archives[] = $junk;
        file_put_contents($junk, 'not a zip archive');
        $manager = [
            'rest' => $harness->token('rest', ['extensions.manage']),
            'cli' => $harness->token('cli', ['extensions.manage']),
        ];
        $reader = [
            'rest' => $harness->token('rest', ['content.read']),
            'cli' => $harness->token('cli', ['content.read']),
        ];
        $rest = static fn (string $token, string $bytes, string $query = ''): int => $harness->rest(
            $token,
            'POST',
            '/api/v1/extensions' . $query,
            $bytes,
            ['Idempotency-Key' => 'extension-install-refusal-' . bin2hex(random_bytes(6))],
        )['status'];
        $cli = static fn (string $token, array $arguments): int => $harness->cli(
            InstallExtensionCommand::class,
            $token,
            $arguments,
        )['status'];

        self::assertSame([
            'denied' => 403,
            'half_signature' => 422,
            'not_a_package' => 422,
        ], [
            'denied' => $rest($reader['rest'], self::bytes($archive)),
            'half_signature' => $rest($manager['rest'], self::bytes($archive), '?key_id=parity'),
            'not_a_package' => $rest($manager['rest'], 'not a zip archive'),
        ]);
        self::assertSame([
            'denied' => 1,
            'half_signature' => 64,
            'not_a_package' => 1,
        ], [
            'denied' => $cli($reader['cli'], [$archive]),
            'half_signature' => $cli($manager['cli'], [$archive, '--key-id=parity']),
            'not_a_package' => $cli($manager['cli'], [$junk]),
        ]);
        self::assertNull(self::row($container, $identifier));
        self::assertSame(0, self::installEvents($container, $identifier));
    }

    /**
     * Boot a fresh kernel, as a reloaded process would be, and a harness bound to it.
     *
     * @return  array{Container, MachineSurfaceHarness}  Kernel and harness.
     *
     * @since   2.0.0
     */
    private function boot(): array
    {
        $this->container = TestKernelFactory::create(Environment::fromGlobals());
        $harness = new MachineSurfaceHarness($this->container, 'extension-install-parity');
        $this->harnesses[] = $harness;

        return [$this->container, $harness];
    }

    /**
     * Write one minimal unsigned plugin package.
     *
     * @param   string  $identifier  `vendor/name` the manifest declares.
     *
     * @return  string  Archive path.
     *
     * @since   2.0.0
     */
    private function package(string $identifier): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'kumwe-parity-extension-');
        if (!is_string($archive)) {
            throw new RuntimeException('The parity extension archive could not be allocated.');
        }
        $this->archives[] = $archive;
        $namespace = 'KumweParity\\Install' . bin2hex(random_bytes(4));
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The parity extension archive could not be opened.');
        }
        try {
            $zip->addFromString('kumwe.json', json_encode([
                'schema' => 1,
                'name' => $identifier,
                'type' => 'plugin',
                'version' => '1.0.0',
                'provider' => $namespace . '\\Provider',
                'autoload' => ['psr-4' => [$namespace . '\\' => 'src/']],
                'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
                'dependencies' => [],
                'migrations' => [],
                'configuration' => [],
                'permissions' => [],
                'routes' => [],
                'events' => [],
                'assets' => [],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->addFromString('src/Provider.php', sprintf(<<<'PHP'
<?php

declare(strict_types=1);

namespace %s;

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
PHP, $namespace));
        } finally {
            $zip->close();
        }

        return $archive;
    }

    /**
     * Read one archive's bytes.
     *
     * @param   string  $archive  Archive path.
     *
     * @return  string  Raw bytes.
     *
     * @since   2.0.0
     */
    private static function bytes(string $archive): string
    {
        $bytes = file_get_contents($archive);
        self::assertIsString($bytes);

        return $bytes;
    }

    /**
     * Read the registry row of one extension as the extensions screen lists it.
     *
     * @param   Container  $container   Kernel.
     * @param   string     $identifier  `vendor/name`.
     *
     * @return  ?array<string, mixed>  Row, or null when not installed.
     *
     * @since   2.0.0
     */
    private static function row(Container $container, string $identifier): ?array
    {
        $extensions = $container->get(ExtensionManager::class);
        self::assertInstanceOf(ExtensionManager::class, $extensions);
        foreach ($extensions->installed(TestKernelFactory::administratorContext($container)) as $row) {
            if (($row['identifier'] ?? null) === $identifier) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Count the install audit events recorded for one extension.
     *
     * @param   Container  $container   Kernel.
     * @param   string     $identifier  `vendor/name`.
     *
     * @return  int  Number of `extension.install` events.
     *
     * @since   2.0.0
     */
    private static function installEvents(Container $container, string $identifier): int
    {
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);

        return (int) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE action = ? AND subject_type = ? AND subject_id = ?',
            $tables->quoted('audit_events'),
        ), ['extension.install', 'extension', str_replace('/', ':', $identifier)]);
    }
}
