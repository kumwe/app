<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Extension;

use Doctrine\DBAL\Connection;
use Kumwe\App\Administrator\Http\Handler\AdministratorExtensionActionHandler;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\Delivery\Console\Command\ActivateExtensionCommand;
use Kumwe\App\Delivery\Http\Api\Extension\ExtensionApiHandler;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\MachineSurfaceHarness;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\Localization\Application\Translator;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use ZipArchive;

/**
 * Proves reactivating an extension whose business schema can no longer be re-proved is refused, not a 500.
 *
 * An extension contributes a business definition whose schema is installed through the ordinary plan path,
 * the extension is disabled, and its tables are then lost. Reactivation now needs an approved
 * synchronization plan first. Every surface that can reactivate an extension — the administrator screen,
 * REST, the console and MCP — answers with its own refusal convention naming that plan, and none of them
 * leaves a durable trace: the registry row, the schema installation, the owner's definitions and the audit
 * trail read exactly as they did before. The same assertions run on MariaDB and PostgreSQL.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorExtensionActionHandler::class)]
#[CoversClass(ExtensionApiHandler::class)]
#[CoversClass(ActivateExtensionCommand::class)]
#[CoversClass(KumweMcpHandlers::class)]
final class ExtensionSchemaReactivationRefusalIntegrationTest extends TestCase
{
    /**
     * Every surface refuses the reactivation with its own convention and changes nothing durable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReactivationNeedingASynchronizationPlanIsRefusedOnEverySurface(): void
    {
        $environment = Environment::fromGlobals();
        $setup = TestKernelFactory::create($environment);
        $extensions = $setup->get(ExtensionManager::class);
        self::assertInstanceOf(ExtensionManager::class, $extensions);
        $context = TestKernelFactory::administratorContext($setup);
        $marker = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $identifier = 'integration/reactivate_' . $marker;
        $archive = self::package($identifier, $marker);
        $installed = false;
        $harness = null;

        try {
            $extensions->install($archive, $context);
            $installed = true;
            $definition = NeutralBusinessFixture::installContributed($setup, $context, $identifier, [
                NeutralBusinessFixture::documentLineDocument($marker, Uuid::uuid7()->toString(), $identifier),
            ])[0];
            $extensions->disable($identifier, $context);
            self::assertSame(SchemaInstallationStatus::Disabled, self::installation($setup, $definition)['status']);
            self::dropTables($setup, $definition);
            $trust = $setup->get(TrustStore::class);
            self::assertInstanceOf(TrustStore::class, $trust);
            $trust->synchronizeRuntimeMaterialization();

            $container = TestKernelFactory::create($environment);
            $harness = new MachineSurfaceHarness($container, 'extension-schema-reactivation');
            $before = self::state($container, $identifier, $definition);
            self::assertSame('disabled', $before['status']);
            self::assertFalse($before['owner_active']);

            $translator = $container->get(Translator::class);
            self::assertInstanceOf(Translator::class, $translator);
            $handler = $container->get(AdministratorExtensionActionHandler::class);
            self::assertInstanceOf(AdministratorExtensionActionHandler::class, $handler);
            $screen = $handler->handle((new ServerRequestFactory())
                ->createServerRequest('POST', 'https://kumwe.test/administrator/extensions/action')
                ->withParsedBody(['action' => 'activate', 'identifier' => $identifier])
                ->withAttribute(ExecutionContextAttribute::NAME, TestKernelFactory::administratorContext($container)));
            self::assertSame(409, $screen->getStatusCode(), (string) $screen->getBody());
            $problem = json_decode((string) $screen->getBody(), true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($problem);
            self::assertSame('urn:kumwe:problem:business-schema-conflict', $problem['type'] ?? null);
            self::assertSame($translator->translate(
                'core.administrator.extensions.schema_plan_required',
                ['extension' => $identifier],
            ), $problem['detail'] ?? null);
            self::assertStringContainsString('synchronization plan', (string) $problem['detail']);
            self::assertSame($before, self::state($container, $identifier, $definition));

            $rest = $harness->rest(
                $harness->token('rest', ['extensions.manage']),
                'POST',
                '/api/v1/extensions/' . $identifier . '/activate',
                null,
                ['Idempotency-Key' => 'extension-reactivation-' . $marker],
            );
            self::assertSame(409, $rest['status'], $rest['raw']);
            self::assertIsArray($rest['body']);
            self::assertSame('urn:kumwe:problem:business-schema-conflict', $rest['body']['type'] ?? null);
            self::assertStringContainsString('synchronization plan', (string) ($rest['body']['detail'] ?? ''));
            self::assertSame($before, self::state($container, $identifier, $definition));

            $cli = $harness->cli(
                ActivateExtensionCommand::class,
                $harness->token('cli', ['extensions.manage']),
                [$identifier],
            );
            self::assertSame(1, $cli['status']);
            self::assertSame('core.console.extension_activate.schema_plan_required', $cli['stderr']);
            self::assertSame($before, self::state($container, $identifier, $definition));

            $mcp = $harness->mcp($harness->token('mcp', ['extensions.manage']), 'kumwe_extension_activate', [
                'operationId' => 'extension-reactivation-' . $marker,
                'identifier' => $identifier,
            ]);
            self::assertTrue($mcp['error']);
            self::assertIsArray($mcp['value']);
            self::assertSame('extension.schema_plan_required', $mcp['value']['code'] ?? null);
            self::assertStringContainsString('synchronization plan', (string) ($mcp['value']['message'] ?? ''));
            self::assertFalse($mcp['value']['retryable'] ?? null);
            self::assertSame($before, self::state($container, $identifier, $definition));
        } finally {
            $harness?->cleanup();
            if ($installed) {
                $extensions->uninstall($identifier, $context);
            }
            if (is_file($archive)) {
                unlink($archive);
            }
        }
    }

    /**
     * Read everything a reactivation would durably change.
     *
     * @param   Container             $container   Kernel.
     * @param   string                $identifier  `vendor/name`.
     * @param   EntityTypeDefinition  $definition  Contributed definition.
     *
     * @return  array{status: mixed, registry_version: mixed, installation: SchemaInstallationStatus,
     *          owner_active: bool, audit: int}  Durable lifecycle state.
     *
     * @since   2.0.0
     */
    private static function state(Container $container, string $identifier, EntityTypeDefinition $definition): array
    {
        $database = $container->get(Connection::class);
        $tables = $container->get(TableNames::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $row = $database->fetchAssociative(sprintf(
            'SELECT status, registry_version FROM %s WHERE identifier = ?',
            $tables->quoted('extensions'),
        ), [$identifier]);
        self::assertIsArray($row);
        $ownerActive = $database->fetchOne(sprintf(
            'SELECT owner_active FROM %s WHERE id = ?',
            $tables->quoted('business_definitions'),
        ), [$definition->id]);

        return [
            'status' => $row['status'],
            'registry_version' => (int) $row['registry_version'],
            'installation' => self::installation($container, $definition)['status'],
            'owner_active' => (bool) $ownerActive,
            'audit' => (int) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE action = ? AND subject_type = ? AND subject_id = ?',
                $tables->quoted('audit_events'),
            ), ['extension.activate', 'extension', str_replace('/', ':', $identifier)]),
        ];
    }

    /**
     * Read the schema installation of the contributed definition.
     *
     * @param   Container             $container   Kernel.
     * @param   EntityTypeDefinition  $definition  Contributed definition.
     *
     * @return  array{status: SchemaInstallationStatus, tables: list<string>}  Status and physical tables.
     *
     * @since   2.0.0
     */
    private static function installation(Container $container, EntityTypeDefinition $definition): array
    {
        $schemas = $container->get(BusinessSchemaService::class);
        self::assertInstanceOf(BusinessSchemaService::class, $schemas);
        $installation = $schemas->installation(TestKernelFactory::administratorContext($container), $definition->id);
        self::assertNotNull($installation);
        $tables = [];
        foreach ($installation->blueprint->tables() as $table) {
            $tables[] = $table->physicalName;
        }

        return ['status' => $installation->status, 'tables' => $tables];
    }

    /**
     * Lose the installed tables while the owner is disabled, so only a new plan can restore them.
     *
     * @param   Container             $container   Kernel.
     * @param   EntityTypeDefinition  $definition  Contributed definition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function dropTables(Container $container, EntityTypeDefinition $definition): void
    {
        $database = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $manager = $database->createSchemaManager();
        foreach (array_reverse(self::installation($container, $definition)['tables']) as $table) {
            if ($manager->tablesExist([$table])) {
                $manager->dropTable($database->getDatabasePlatform()->quoteSingleIdentifier($table));
            }
        }
    }

    /**
     * Write one minimal unsigned plugin package.
     *
     * @param   string  $identifier  `vendor/name` the manifest declares.
     * @param   string  $marker      Run marker keeping the provider namespace unique.
     *
     * @return  string  Archive path.
     *
     * @since   2.0.0
     */
    private static function package(string $identifier, string $marker): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'kumwe-reactivation-extension-');
        if (!is_string($archive)) {
            throw new RuntimeException('The reactivation extension archive could not be allocated.');
        }
        $namespace = 'KumweIntegration\\Reactivation' . ucfirst($marker);
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The reactivation extension archive could not be opened.');
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
}
