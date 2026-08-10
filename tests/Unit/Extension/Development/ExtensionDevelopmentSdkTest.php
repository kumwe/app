<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Development;

use ArrayObject;
use FilesystemIterator;
use InvalidArgumentException;
use Kumwe\CMS\BusinessIntegration\Application\DomainEventHandler;
use Kumwe\CMS\BusinessIntegration\Application\IntegrationEventHandler;
use Kumwe\CMS\BusinessReporting\Application\ProjectionBuilder;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Command\BuildExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\InspectExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\RunExtensionConformanceCommand;
use Kumwe\CMS\Delivery\Console\Command\ScaffoldExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\SignExtensionCommand;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Application\ExtensionServiceProvider;
use Kumwe\CMS\Extension\Application\Migration\ExtensionMigration;
use Kumwe\CMS\Extension\Application\Package\PackageSafetyPolicy;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionProvider;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Development\ComponentScaffolder;
use Kumwe\CMS\Extension\Development\ConformanceReport;
use Kumwe\CMS\Extension\Development\DeterministicPackageBuilder;
use Kumwe\CMS\Extension\Development\LifecycleConformanceAdapter;
use Kumwe\CMS\Extension\Development\LifecycleConformanceRunner;
use Kumwe\CMS\Extension\Development\PackageInspector;
use Kumwe\CMS\Extension\Development\PackageSigner;
use Kumwe\CMS\Extension\Development\ProtectedSigningKeyReader;
use Kumwe\CMS\Extension\Development\ScaffoldRequest;
use Kumwe\CMS\Extension\Development\SignatureDocument;
use Kumwe\CMS\Extension\Development\StaticConformanceRunner;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Extension\Domain\PackageSignature;
use Kumwe\CMS\Extension\Infrastructure\Package\ZipArchiveReader;
use Kumwe\CMS\Extension\Runtime\RestrictedExtensionContainer;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Exercises the complete scaffold, deterministic build, inspection, conformance, and signing path.
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
     * Prove two builds are identical and the result passes every static check.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompleteScaffoldBuildsReproduciblyAndConforms(): void
    {
        $source = $this->temporary . '/component';
        $scaffold = new ComponentScaffolder();
        $result = $scaffold->scaffold(new ScaffoldRequest(
            'acme/quality-component',
            'Acme\\QualityComponent',
            $source,
            "Owner's Quality Component",
        ));
        self::assertGreaterThanOrEqual(10, $result->fileCount);
        $this->assertGeneratedRuntimeContractsLoad($source);

        $inspector = $this->inspector();
        $builder = new DeterministicPackageBuilder($inspector);
        $first = $builder->build($source, $this->temporary . '/first.zip');
        $second = $builder->build($source, $this->temporary . '/second.zip');

        self::assertSame((string) $first->inspection->checksum, (string) $second->inspection->checksum);
        self::assertSame('acme/quality-component', $first->inspection->manifest->identifier()->value());
        $report = (new StaticConformanceRunner($inspector))->run($first->archive);
        self::assertTrue($report->conforms());
        self::assertFalse((new ConformanceReport($report->inspection, ['forced_failure' => false], []))->conforms());
    }

    /**
     * Load the generated executable contracts and prove they implement the current public SPI exactly.
     *
     * Static token parsing deliberately does not autoload extension code, so this in-process test closes
     * the compatibility gap: a stale interface method or incomplete canonical definition fails the SDK
     * suite before a broken template can ship to extension authors.
     *
     * @param   string  $source  Canonical generated component source directory.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertGeneratedRuntimeContractsLoad(string $source): void
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

        $definitions = 'Acme\\QualityComponent\\Integration\\IntegrationDefinitions';
        $ledgerClass = 'Acme\\QualityComponent\\Integration\\IntegrationLedger';
        $listenerClass = 'Acme\\QualityComponent\\Integration\\ItemDomainListener';
        $consumerClass = 'Acme\\QualityComponent\\Integration\\ItemIntegrationConsumer';
        $projectionClass = 'Acme\\QualityComponent\\Integration\\ItemProjectionBuilder';
        $ledger = new $ledgerClass();
        $listener = $definitions::domainListener();
        $consumer = $definitions::consumer();
        $projection = $definitions::projection();

        self::assertInstanceOf(DomainEventHandler::class, new $listenerClass($listener, $ledger));
        self::assertInstanceOf(IntegrationEventHandler::class, new $consumerClass($consumer, $ledger));
        self::assertInstanceOf(ProjectionBuilder::class, new $projectionClass($projection));

        $manifestJson = file_get_contents($source . '/kumwe.json');
        self::assertIsString($manifestJson);
        $manifest = ExtensionManifest::fromJson($manifestJson);
        $providerClass = 'Acme\\QualityComponent\\Provider';
        $migrationClass = 'Acme\\QualityComponent\\Migration\\CreateComponentRecords';
        $provider = new $providerClass();
        self::assertInstanceOf(ExtensionServiceProvider::class, $provider);
        self::assertInstanceOf(ExtensionContributionProvider::class, $provider);
        self::assertInstanceOf(ExtensionMigration::class, new $migrationClass());

        $declarations = $manifest->contributions();
        $registries = new ExtensionContributionRegistrySet();
        $container = new RestrictedExtensionContainer($manifest->identifier()->value(), []);
        $provider->register($container);
        $registrar = $registries->registrar($declarations->owner, $declarations);
        $provider->contribute($registrar, $container);
        $registrar->complete();
        $registries->validateBusinessDefinitions();
        $catalog = $registries->validateIntegrationContributions();

        self::assertSame('acme.quality-component.item_consumer', $catalog->consumer(
            'acme.quality-component.item_consumer',
        )->identifier());
        $inventory = $registries->inventory($declarations->owner);
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
     * Prove the lifecycle runner invokes every explicit conformance gate in dependency order.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLifecycleConformanceRunsEveryGateAndRecovery(): void
    {
        [$base, $upgrade] = $this->lifecyclePackages();
        $calls = new ArrayObject();
        $report = (new LifecycleConformanceRunner(new StaticConformanceRunner($this->inspector())))->run(
            $this->lifecycleAdapter($calls),
            $base,
            $upgrade,
        );

        self::assertTrue($report->conforms(), implode("\n", $report->violations));
        self::assertFalse(in_array(false, $report->checks, true));
        self::assertSame([
            'package_safety_and_signing',
            'schema_plan',
            'install',
            'definitions',
            'authorization_and_field_policies',
            'routes',
            'rest_and_openapi',
            'cli_and_mcp',
            'jobs_events_and_reports',
            'portal_and_administrator',
            'backup_and_restore',
            'upgrade',
            'disable',
            'reactivate',
            'database_matrix',
            'uninstall',
            'recovery',
        ], $calls->getArrayCopy());
    }

    /**
     * Prove a failed definition reconciliation stops dependent gates but cannot suppress recovery.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLifecycleConformanceStopsAfterFailureAndStillRecovers(): void
    {
        [$base, $upgrade] = $this->lifecyclePackages();
        $calls = new ArrayObject();
        $report = (new LifecycleConformanceRunner(new StaticConformanceRunner($this->inspector())))->run(
            $this->lifecycleAdapter($calls, 'definitions'),
            $base,
            $upgrade,
        );

        self::assertFalse($report->conforms());
        self::assertTrue($report->checks['static_base_package']);
        self::assertTrue($report->checks['static_upgrade_package']);
        self::assertTrue($report->checks['package_safety_and_signing']);
        self::assertTrue($report->checks['schema_plan']);
        self::assertTrue($report->checks['install']);
        self::assertFalse($report->checks['definitions']);
        self::assertFalse($report->checks['authorization_and_field_policies']);
        self::assertTrue($report->checks['recovery']);
        self::assertSame([
            'package_safety_and_signing',
            'schema_plan',
            'install',
            'definitions',
            'recovery',
        ], $calls->getArrayCopy());
        self::assertSame(['definitions: forced definitions failure'], $report->violations);
    }

    /**
     * Prove protected seed decoding produces a verifiable detached signature sidecar.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProtectedSigningProducesPortableVerifiableSidecar(): void
    {
        $source = $this->temporary . '/component';
        (new ComponentScaffolder())->scaffold(new ScaffoldRequest(
            'acme/signed-component',
            'Acme\\SignedComponent',
            $source,
            'Signed Component',
        ));
        $inspector = $this->inspector();
        $archive = (new DeterministicPackageBuilder($inspector))
            ->build($source, $this->temporary . '/signed.zip')->archive;
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $keyFile = $this->temporary . '/signing.key';
        self::assertSame(64, file_put_contents($keyFile, bin2hex($seed), LOCK_EX));
        self::assertTrue(chmod($keyFile, 0600));

        $signer = new PackageSigner(new ProtectedSigningKeyReader(), $inspector);
        $document = $signer->sign($archive, 'release-2026', $keyFile);
        $sidecar = $this->temporary . '/signed.signature.json';
        $signer->write($document, $sidecar);
        $decoded = SignatureDocument::fromJson((string) file_get_contents($sidecar));
        $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));

        self::assertTrue(sodium_crypto_sign_verify_detached(
            PackageSignature::ed25519($decoded->keyId, $decoded->base64Signature)->bytes(),
            $decoded->packageSha256,
            $public,
        ));
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
     * Prove development cache material is omitted and common private-key or environment files fail closed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBuilderOmitsDevelopmentCachesAndRejectsSensitiveFiles(): void
    {
        $source = $this->temporary . '/component';
        (new ComponentScaffolder())->scaffold(new ScaffoldRequest(
            'acme/safe-component',
            'Acme\\SafeComponent',
            $source,
            'Safe Component',
        ));
        self::assertTrue(mkdir($source . '/.phpunit.cache', 0700));
        self::assertSame(13, file_put_contents($source . '/.phpunit.cache/results', "test-results\n", LOCK_EX));
        $builder = new DeterministicPackageBuilder($this->inspector());
        $result = $builder->build($source, $this->temporary . '/without-cache.zip');

        self::assertNotContains('.gitignore', $result->inspection->paths);
        self::assertNotContains('.phpunit.cache/results', $result->inspection->paths);
        self::assertSame(13, file_put_contents($source . '/.env.production', "SECRET=value\n", LOCK_EX));

        $this->expectException(RuntimeException::class);
        $builder->build($source, $this->temporary . '/unsafe.zip');
    }

    /**
     * Prove strict-types text inside a comment cannot satisfy the executable declaration gate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStaticConformanceRejectsCommentOnlyStrictTypesMarker(): void
    {
        $source = $this->temporary . '/component';
        (new ComponentScaffolder())->scaffold(new ScaffoldRequest(
            'acme/non-strict-component',
            'Acme\\NonStrictComponent',
            $source,
            'Non-strict Component',
        ));
        $path = $source . '/src/Application/OverviewService.php';
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $unsafe = str_replace('declare(strict_types=1);', '// declare(strict_types=1);', $contents, $replacements);
        self::assertSame(1, $replacements);
        self::assertSame(strlen($unsafe), file_put_contents($path, $unsafe, LOCK_EX));

        $archive = (new DeterministicPackageBuilder($this->inspector()))
            ->build($source, $this->temporary . '/non-strict.zip')->archive;
        $report = (new StaticConformanceRunner($this->inspector()))->run($archive);

        self::assertFalse($report->conforms());
        self::assertFalse($report->checks['strict_types']);
        self::assertContains(
            'PHP file src/Application/OverviewService.php must declare strict_types=1.',
            $report->violations,
        );
    }

    /**
     * Prove signing material with group access is rejected before decoding.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSigningKeyReaderRejectsGroupReadableFiles(): void
    {
        $keyFile = $this->temporary . '/unsafe.key';
        self::assertSame(64, file_put_contents($keyFile, bin2hex(random_bytes(32)), LOCK_EX));
        self::assertTrue(chmod($keyFile, 0640));

        $this->expectException(InvalidArgumentException::class);
        (new ProtectedSigningKeyReader())->read($keyFile);
    }

    /**
     * Build canonical base and upgrade packages used by lifecycle-runner contract tests.
     *
     * @return  array{string, string}  Base and upgrade archive paths.
     *
     * @since   2.0.0
     */
    private function lifecyclePackages(): array
    {
        $baseSource = $this->temporary . '/lifecycle-base-source';
        $upgradeSource = $this->temporary . '/lifecycle-upgrade-source';
        (new ComponentScaffolder())->scaffold(new ScaffoldRequest(
            'acme/lifecycle-component',
            'Acme\\LifecycleComponent',
            $baseSource,
            'Lifecycle Component',
        ));
        (new ComponentScaffolder())->scaffold(new ScaffoldRequest(
            'acme/lifecycle-component',
            'Acme\\LifecycleComponent',
            $upgradeSource,
            'Lifecycle Component',
            '1.1.0',
        ));
        $builder = new DeterministicPackageBuilder($this->inspector());

        return [
            $builder->build($baseSource, $this->temporary . '/lifecycle-base.zip')->archive,
            $builder->build($upgradeSource, $this->temporary . '/lifecycle-upgrade.zip')->archive,
        ];
    }

    /**
     * Build a recording platform adapter that may fail at one named lifecycle gate.
     *
     * @param   ArrayObject<int, string>  $calls    Mutable ordered call evidence.
     * @param   ?string                   $failure  Gate that must throw, or null for a successful run.
     *
     * @return  LifecycleConformanceAdapter  Complete deterministic test adapter.
     *
     * @since   2.0.0
     */
    private function lifecycleAdapter(ArrayObject $calls, ?string $failure = null): LifecycleConformanceAdapter
    {
        return new class ($calls, $failure) implements LifecycleConformanceAdapter {
            /**
             * Retain mutable call evidence and the optional forced failure.
             *
             * @param  ArrayObject<int, string>  $calls    Mutable ordered call evidence.
             * @param  ?string                   $failure  Gate that must throw.
             */
            public function __construct(
                private ArrayObject $calls,
                private ?string $failure,
            ) {
            }

            public function assertPackageSafetyAndSigning(string $basePackage, string $upgradePackage): void
            {
                $this->packages($basePackage, $upgradePackage);
                $this->pass('package_safety_and_signing');
            }

            public function assertSchemaPlan(string $basePackage, string $upgradePackage): void
            {
                $this->packages($basePackage, $upgradePackage);
                $this->pass('schema_plan');
            }

            public function install(string $basePackage): void
            {
                if (!is_file($basePackage)) {
                    throw new RuntimeException('The base package is unavailable.');
                }
                $this->pass('install');
            }

            public function assertDefinitions(): void
            {
                $this->pass('definitions');
            }

            public function assertAuthorizationAndFieldPolicies(): void
            {
                $this->pass('authorization_and_field_policies');
            }

            public function assertRoutes(): void
            {
                $this->pass('routes');
            }

            public function assertRestAndOpenApi(): void
            {
                $this->pass('rest_and_openapi');
            }

            public function assertCliAndMcp(): void
            {
                $this->pass('cli_and_mcp');
            }

            public function assertJobsEventsAndReports(): void
            {
                $this->pass('jobs_events_and_reports');
            }

            public function assertPortalAndAdministrator(): void
            {
                $this->pass('portal_and_administrator');
            }

            public function assertBackupAndRestore(): void
            {
                $this->pass('backup_and_restore');
            }

            public function upgrade(string $upgradePackage): void
            {
                if (!is_file($upgradePackage)) {
                    throw new RuntimeException('The upgrade package is unavailable.');
                }
                $this->pass('upgrade');
            }

            public function disable(): void
            {
                $this->pass('disable');
            }

            public function reactivate(): void
            {
                $this->pass('reactivate');
            }

            public function assertDatabaseMatrix(string $basePackage, string $upgradePackage): void
            {
                $this->packages($basePackage, $upgradePackage);
                $this->pass('database_matrix');
            }

            public function uninstall(): void
            {
                $this->pass('uninstall');
            }

            public function recover(): void
            {
                $this->pass('recovery');
            }

            /**
             * Require both lifecycle packages to remain available to the adapter.
             *
             * @param   string  $basePackage     Base package path.
             * @param   string  $upgradePackage  Upgrade package path.
             *
             * @return  void
             */
            private function packages(string $basePackage, string $upgradePackage): void
            {
                if (!is_file($basePackage) || !is_file($upgradePackage)) {
                    throw new RuntimeException('A lifecycle package is unavailable.');
                }
            }

            /**
             * Record one gate and optionally throw its configured deterministic failure.
             *
             * @param   string  $gate  Stable lifecycle gate name.
             *
             * @return  void
             */
            private function pass(string $gate): void
            {
                $this->calls->append($gate);
                if ($this->failure === $gate) {
                    throw new RuntimeException('forced ' . $gate . ' failure');
                }
            }
        };
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
        return new PackageInspector(new ZipArchiveReader(), new PackageSafetyPolicy());
    }
}
