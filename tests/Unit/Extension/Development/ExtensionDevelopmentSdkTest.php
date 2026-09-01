<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Development;

use Kumwe\App\Tests\Support\TranslatesConsoleOutput;
use FilesystemIterator;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Command\BuildExtensionCommand;
use Kumwe\App\Delivery\Console\Command\InspectExtensionCommand;
use Kumwe\App\Delivery\Console\Command\RunExtensionConformanceCommand;
use Kumwe\App\Delivery\Console\Command\ScaffoldExtensionCommand;
use Kumwe\App\Delivery\Console\Command\SignExtensionCommand;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\Extension\Spi\Application\ExtensionServiceProvider;
use Kumwe\Extension\Spi\Binding\ExtensionBindingProvider;
use Kumwe\Extension\Spi\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionBuilder;
use Kumwe\Extension\Spi\Migration\ExtensionMigration;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\Extension\Toolchain\ComponentScaffolder;
use Kumwe\Extension\Toolchain\DeterministicPackageBuilder;
use Kumwe\Extension\Toolchain\PackageInspector;
use Kumwe\Extension\Toolchain\PackageSigner;
use Kumwe\Extension\Toolchain\ProtectedSigningKeyReader;
use Kumwe\Extension\Toolchain\StaticConformanceRunner;
use Kumwe\Extension\Manifest\ExtensionManifest;
use Kumwe\App\Extension\Runtime\RestrictedExtensionContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(BuildExtensionCommand::class)]
#[CoversClass(InspectExtensionCommand::class)]
#[CoversClass(RunExtensionConformanceCommand::class)]
#[CoversClass(ScaffoldExtensionCommand::class)]
#[CoversClass(SignExtensionCommand::class)]
/**
 * Proves the App's five development commands drive one scaffold, build, inspect, conformance, and sign workflow.
 *
 * The toolchain itself is proven in kumwe/extension-sdk; what the App owns is the console binding of each
 * step and the proof that a scaffolded provider binds through the App's own contribution registries.
 *
 * @since  2.0.0
 */
final class ExtensionDevelopmentSdkTest extends TestCase
{
    /**
     * Canonical private root allocated for one test invocation.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $temporary;

    /**
     * Allocate a private test root with a canonical absolute path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = sys_get_temp_dir() . '/kumwe-extension-sdk-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->temporary, 0700));
    }

    /**
     * Remove only the private root allocated by this test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        if (isset($this->temporary) && is_dir($this->temporary) && !is_link($this->temporary)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->temporary, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                if (!$entry instanceof SplFileInfo) {
                    continue;
                }
                if ($entry->isDir() && !$entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($this->temporary);
        }
        parent::tearDown();
    }

    /**
     * Load the generated executable contracts and prove they bind through the App's contribution registries.
     *
     * Static token parsing deliberately does not autoload extension code, so this in-process step closes
     * the compatibility gap: a stale interface method or incomplete canonical definition fails here, in
     * the App's own registry set and restricted container, before a broken template can ship to authors.
     *
     * @param   string  $source     Canonical generated component source directory.
     * @param   string  $namespace  Root PHP namespace the scaffold generated its classes under.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertGeneratedRuntimeContractsLoad(string $source, string $namespace): void
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source . '/src', FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            require_once $file;
        }

        $ledgerClass = $namespace . '\\Integration\\IntegrationLedger';
        $listenerClass = $namespace . '\\Integration\\ItemDomainListener';
        $consumerClass = $namespace . '\\Integration\\ItemIntegrationConsumer';
        $projectionClass = $namespace . '\\Integration\\ItemProjectionBuilder';
        $ledger = new $ledgerClass();

        self::assertInstanceOf(DomainEventHandler::class, new $listenerClass($ledger));
        self::assertInstanceOf(IntegrationEventHandler::class, new $consumerClass($ledger));
        self::assertInstanceOf(ProjectionBuilder::class, new $projectionClass());

        $manifestJson = file_get_contents($source . '/kumwe.json');
        self::assertIsString($manifestJson);
        $manifest = ExtensionManifest::fromJson($manifestJson);
        $providerClass = $namespace . '\\Provider';
        $migrationClass = $namespace . '\\Migration\\CreateComponentRecords';
        $provider = new $providerClass();
        self::assertInstanceOf(ExtensionServiceProvider::class, $provider);
        self::assertInstanceOf(ExtensionBindingProvider::class, $provider);
        self::assertInstanceOf(ExtensionMigration::class, new $migrationClass());

        $declarations = $manifest->contributions();
        $registries = new ExtensionContributionRegistrySet();
        $container = new RestrictedExtensionContainer($manifest->identifier()->value(), []);
        $provider->register($container);
        $registrar = $registries->activateManifest($declarations);
        $provider->bind($registrar, $container);
        $registrar->complete();
        $registries->validateBusinessDefinitions();
        $catalog = $registries->validateIntegrationContributions();

        $consumer = $declarations->owner->namespace() . '.item_consumer';
        self::assertSame($consumer, $catalog->consumer($consumer)->identifier());
        $inventory = $registries->inventory($declarations->owner);
        self::assertCount(2, $inventory['interface']['surfaces']);
        self::assertCount(1, $inventory['business']['definitions']);
        self::assertCount(1, $inventory['integration']['event_schemas']);
        self::assertCount(1, $inventory['integration']['domain_listeners']);
        self::assertCount(1, $inventory['integration']['consumers']);
        self::assertCount(1, $inventory['integration']['jobs']);
        self::assertCount(1, $inventory['integration']['queues']);
        self::assertCount(1, $inventory['integration']['schedules']);
        self::assertCount(1, $inventory['integration']['projections']);
        self::assertCount(1, $inventory['integration']['reports']);
    }

    /**
     * Prove all five development commands execute the same scaffold, build, inspect, conformance, and sign path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDevelopmentCommandsExecuteCompleteWorkflow(): void
    {
        $output = new class implements Output {
            use TranslatesConsoleOutput;

            /** @var list<string> */
            public array $lines = [];

            /** @var list<string> */
            public array $errors = [];

            public function line(string $message): void
            {
                $this->lines[] = $message;
            }

            public function error(string $message): void
            {
                $this->errors[] = $message;
            }
        };
        $source = $this->temporary . '/command-component';
        $archive = $this->temporary . '/command-component.zip';
        $sidecar = $this->temporary . '/command-component.signature.json';
        $keyFile = $this->temporary . '/command-signing.key';
        $inspector = $this->inspector();
        /** @var list<Command> $commands */
        $commands = [
            new ScaffoldExtensionCommand(new ComponentScaffolder()),
            new BuildExtensionCommand(new DeterministicPackageBuilder($inspector)),
            new InspectExtensionCommand($inspector),
            new RunExtensionConformanceCommand(new StaticConformanceRunner($inspector)),
            new SignExtensionCommand(new PackageSigner(new ProtectedSigningKeyReader(), $inspector)),
        ];

        self::assertSame([
            'extension:scaffold',
            'extension:build',
            'extension:inspect',
            'extension:conformance',
            'extension:sign',
        ], array_map(static fn (Command $command): string => $command->name(), $commands));
        self::assertSame(0, $commands[0]->execute([
            'acme/command-component',
            '--namespace=Acme\\CommandComponent',
            '--target=' . $source,
            '--label=Command Component',
        ], $output));
        self::assertSame(0, $commands[1]->execute([$source, '--output=' . $archive], $output));
        $this->assertGeneratedRuntimeContractsLoad($source, 'Acme\\CommandComponent');
        self::assertSame(0, $commands[2]->execute([$archive], $output));
        self::assertSame(0, $commands[3]->execute([$archive], $output));
        self::assertSame(64, file_put_contents($keyFile, bin2hex(random_bytes(32)), LOCK_EX));
        self::assertTrue(chmod($keyFile, 0600));
        self::assertSame(0, $commands[4]->execute([
            $archive,
            '--key-id=command-release',
            '--secret-key-file=' . $keyFile,
            '--output=' . $sidecar,
        ], $output));

        self::assertSame([], $output->errors);
        self::assertCount(5, $output->lines);
        foreach ($output->lines as $line) {
            self::assertIsArray(json_decode($line, true, 64, JSON_THROW_ON_ERROR));
        }
        self::assertFileExists($archive);
        self::assertFileExists($sidecar);
    }

    /**
     * Build the production-safe package inspection service used by each test path.
     *
     * @return  PackageInspector  Inspector with shipped installation limits.
     *
     * @since   2.0.0
     */
    private function inspector(): PackageInspector
    {
        return new PackageInspector();
    }
}
