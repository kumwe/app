<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Infrastructure;

use FilesystemIterator;
use InvalidArgumentException;
use Kumwe\App\Extension\Application\Install\ExtensionInstallOutcome;
use Kumwe\App\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\App\Extension\Infrastructure\Trust\FilesystemExtensionArtifactVerifier;
use Kumwe\Extension\Manifest\ExtensionManifest;
use Kumwe\Extension\Package\InspectedPackage;
use Kumwe\Extension\Package\PackageLimits;
use Kumwe\Extension\Package\ZipArchiveContentReader;
use Kumwe\Extension\Toolchain\PackageInspector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

#[CoversClass(DoctrineExtensionManager::class)]
/**
 * Proves package staging, replay integrity and contribution diagnostics at the filesystem boundary.
 *
 * @since  2.0.0
 */
final class DoctrineExtensionManagerTest extends TestCase
{
    /**
     * Private roots allocated by this test invocation.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $temporaryRoots = [];

    /**
     * Remove every private filesystem fixture after each test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->temporaryRoots as $root) {
            if (!is_dir($root) || is_link($root)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
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
            rmdir($root);
        }
        parent::tearDown();
    }

    /**
     * Proves later caller writes cannot change the private archive snapshot used for admission.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCallerMutationCannotChangePrivateArchiveSnapshot(): void
    {
        $root = $this->temporaryRoot('snapshot');
        mkdir($root . '/operation', 0700, true);
        $source = $root . '/caller.zip';
        $original = str_repeat('signed-package-bytes', 4096);
        file_put_contents($source, $original);
        $reflection = new ReflectionClass(DoctrineExtensionManager::class);
        $manager = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('packages')->setValue($manager, new PackageInspector());
        $snapshot = $reflection->getMethod('snapshotArchive')->invoke($manager, $source, $root . '/operation');
        file_put_contents($source, 'attacker replacement after the snapshot boundary');

        self::assertIsString($snapshot);
        self::assertSame(hash('sha256', $original), hash_file('sha256', $snapshot));
        self::assertNotSame(hash_file('sha256', $source), hash_file('sha256', $snapshot));
    }

    /**
     * Refuses an oversized caller archive before copying any of it into private staging.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOversizedCallerArchiveIsRefusedBeforeSnapshotCopy(): void
    {
        $root = $this->temporaryRoot('snapshot-limit');
        mkdir($root . '/operation', 0700, true);
        $source = $root . '/caller.zip';
        file_put_contents($source, str_repeat('x', 33));
        $reflection = new ReflectionClass(DoctrineExtensionManager::class);
        $manager = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('packages')->setValue($manager, new PackageInspector(new PackageLimits(
            maximumArchiveBytes: 32,
        )));

        try {
            $reflection->getMethod('snapshotArchive')->invoke($manager, $source, $root . '/operation');
            self::fail('An oversized caller archive reached private snapshot copying.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('exceeds the configured package-size limit', $failure->getMessage());
        }
    }

    /**
     * A freshly expanded deployment verifies against its independent inspected-package content map.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFreshDeploymentExactlyMatchesInspectedPackage(): void
    {
        [$manager, $reflection, $package, $deployment] = $this->deployedPackage('fresh');

        $digest = $reflection->getMethod('verifiedDeployedTreeDigest')->invoke(
            $manager,
            $package,
            $deployment,
        );

        self::assertIsString($digest);
        self::assertSame(FilesystemExtensionArtifactVerifier::treeDigest($deployment), $digest);
    }

    /**
     * A replay refuses a deployment whose package-owned bytes were altered after publication.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReplayRefusesAlteredDeploymentFile(): void
    {
        [$manager, $reflection, $package, $deployment] = $this->deployedPackage('altered');
        $contents = '<?php return "altered";';
        self::assertSame(strlen($contents), file_put_contents($deployment . '/src/Provider.php', $contents));

        $this->assertDeploymentMismatch($manager, $reflection, $package, $deployment);
    }

    /**
     * A replay refuses a deployment from which one inspected package file is missing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReplayRefusesMissingDeploymentFile(): void
    {
        [$manager, $reflection, $package, $deployment] = $this->deployedPackage('missing');
        self::assertTrue(unlink($deployment . '/README.md'));

        $this->assertDeploymentMismatch($manager, $reflection, $package, $deployment);
    }

    /**
     * A replay refuses a deployment carrying a file absent from the inspected package.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReplayRefusesExtraDeploymentFile(): void
    {
        [$manager, $reflection, $package, $deployment] = $this->deployedPackage('extra');
        $contents = '<?php return false;';
        self::assertSame(strlen($contents), file_put_contents($deployment . '/unpack-injected.php', $contents));

        $this->assertDeploymentMismatch($manager, $reflection, $package, $deployment);
    }

    /**
     * A package cannot claim the path reserved for App-owned recovery metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPackageCannotDeclareRetainedArchivePath(): void
    {
        [$manager, $reflection, $package] = $this->inspectedPackage('reserved', [
            FilesystemExtensionArtifactVerifier::ARTIFACT => 'package-owned replacement',
        ]);

        try {
            $reflection->getMethod('assertNoReservedPackagePaths')->invoke($manager, $package);
            self::fail('The App-owned retained archive path was accepted as package content.');
        } catch (InvalidArgumentException $failure) {
            self::assertStringContainsString('reserved by the App runtime', $failure->getMessage());
        }
    }

    /**
     * A known replay mismatch retires every unreferenced tree and settles as rolled back.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testKnownReplayMismatchRetiresUnreferencedDeployment(): void
    {
        [$manager, $reflection, , $deployment, $relativeRuntime, $extensionRoot, $publicRoot]
            = $this->deployedPackage('retire');
        $staging = $extensionRoot . '/.staging/replay-operation';
        $assets = $publicRoot . '/' . $relativeRuntime;
        self::assertTrue(mkdir($staging, 0700, true));
        self::assertSame(5, file_put_contents($staging . '/stale', 'stale'));
        self::assertTrue(mkdir($assets, 0755, true));
        self::assertSame(5, file_put_contents($assets . '/stale', 'stale'));

        $outcome = $reflection->getMethod('retireMismatchedDeployment')->invoke(
            $manager,
            $staging,
            $deployment,
            $relativeRuntime,
            null,
        );

        self::assertSame(ExtensionInstallOutcome::RolledBack, $outcome);
        self::assertDirectoryDoesNotExist($staging);
        self::assertDirectoryDoesNotExist($deployment);
        self::assertDirectoryDoesNotExist($assets);
    }

    /**
     * Rollback never deletes a runtime path already owned by the installed release.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testKnownReplayMismatchPreservesInstalledRuntimePath(): void
    {
        [$manager, $reflection, , $deployment, $relativeRuntime, $extensionRoot, $publicRoot]
            = $this->deployedPackage('referenced');
        $staging = $extensionRoot . '/.staging/replay-operation';
        $assets = $publicRoot . '/' . $relativeRuntime;
        self::assertTrue(mkdir($staging, 0700, true));
        self::assertTrue(mkdir($assets, 0755, true));

        $outcome = $reflection->getMethod('retireMismatchedDeployment')->invoke(
            $manager,
            $staging,
            $deployment,
            $relativeRuntime,
            ['runtime_path' => $relativeRuntime],
        );

        self::assertSame(ExtensionInstallOutcome::RolledBack, $outcome);
        self::assertDirectoryDoesNotExist($staging);
        self::assertDirectoryExists($deployment);
        self::assertDirectoryExists($assets);
    }

    /**
     * Field-presentation declarations carry the same live or dormant diagnostic state as their owner.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContributionDiagnosticsMarkFieldPresentationsActive(): void
    {
        $reflection = new ReflectionClass(DoctrineExtensionManager::class);
        $manager = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('contributionDiagnostics');
        $manifest = ExtensionManifest::fromJson(json_encode([
            'schema' => 3,
            'name' => 'acme/editor',
            'type' => 'component',
            'version' => '2.0.0',
            'provider' => 'Acme\\Editor\\Provider',
            'autoload' => ['psr-4' => ['Acme\\Editor\\' => 'src/']],
            'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
            'contributions' => [
                'version' => 1,
                'business' => [
                    'field_types' => [[
                        'id' => 'acme.editor.code',
                        'label' => 'Code',
                        'description' => 'A bounded extension-owned code.',
                        'value_type' => 'string',
                        'storage_type' => 'string',
                        'configuration_keys' => [],
                    ]],
                    'definitions' => [],
                    'field_presentations' => [[
                        'field_type' => 'acme.editor.code',
                        'contexts' => ['detail', 'update'],
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $active = $method->invoke($manager, $manifest, true);
        $dormant = $method->invoke($manager, $manifest, false);

        self::assertIsArray($active);
        self::assertIsArray($dormant);
        $activeBusiness = $active['business'] ?? null;
        $dormantBusiness = $dormant['business'] ?? null;
        self::assertIsArray($activeBusiness);
        self::assertIsArray($dormantBusiness);
        $activePresentations = $activeBusiness['field_presentations'] ?? null;
        $dormantPresentations = $dormantBusiness['field_presentations'] ?? null;
        self::assertIsArray($activePresentations);
        self::assertIsArray($dormantPresentations);
        $activePresentation = $activePresentations[0] ?? null;
        $dormantPresentation = $dormantPresentations[0] ?? null;
        self::assertIsArray($activePresentation);
        self::assertIsArray($dormantPresentation);
        $activeFlag = $activePresentation['active'] ?? null;
        $dormantFlag = $dormantPresentation['active'] ?? null;
        self::assertIsBool($activeFlag);
        self::assertIsBool($dormantFlag);
        self::assertTrue($activeFlag);
        self::assertFalse($dormantFlag);
    }

    /**
     * Build and expand one valid inspected package under private test roots.
     *
     * @param   string  $label  Path-safe identifier distinguishing this fixture.
     *
     * @return  array{DoctrineExtensionManager, ReflectionClass<DoctrineExtensionManager>, InspectedPackage,
     *          string, string, string, string}  Manager, reflection, package, deployment, relative runtime,
     *          extension root and public-asset root.
     *
     * @since   2.0.0
     */
    private function deployedPackage(string $label): array
    {
        [$manager, $reflection, $package, , $extensionRoot, $publicRoot]
            = $this->inspectedPackage($label);
        $relativeRuntime = 'fixture/' . $label . '/1.0.0';
        $deployment = $extensionRoot . '/' . $relativeRuntime;
        $reflection->getMethod('extract')->invoke($manager, $package, $deployment);
        self::assertTrue(copy(
            $package->archive,
            $deployment . '/' . FilesystemExtensionArtifactVerifier::ARTIFACT,
        ));

        return [
            $manager,
            $reflection,
            $package,
            $deployment,
            $relativeRuntime,
            $extensionRoot,
            $publicRoot,
        ];
    }

    /**
     * Build one immutable SDK package snapshot and a manager wired to its content reader.
     *
     * @param   string                 $label         Path-safe identifier distinguishing this fixture.
     * @param   array<string, string>  $extraEntries  Additional regular archive entries by portable path.
     *
     * @return  array{DoctrineExtensionManager, ReflectionClass<DoctrineExtensionManager>, InspectedPackage,
     *          string, string, string}  Manager, reflection, package, private root, extension root and
     *          public-asset root.
     *
     * @since   2.0.0
     */
    private function inspectedPackage(string $label, array $extraEntries = []): array
    {
        $root = $this->temporaryRoot('deployed-' . $label);
        $archive = $root . '/package.zip';
        $manifest = json_encode([
            'schema' => 1,
            'name' => 'fixture/' . $label,
            'type' => 'plugin',
            'version' => '1.0.0',
            'provider' => 'Fixture\\Package\\Provider',
            'autoload' => ['psr-4' => ['Fixture\\Package\\' => 'src/']],
            'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
            'dependencies' => [],
            'migrations' => [],
            'configuration' => new \stdClass(),
            'permissions' => [],
            'routes' => [],
            'events' => [],
            'assets' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertIsString($manifest);
        $entries = [
            'kumwe.json' => $manifest,
            'src/Provider.php' => "<?php\n\ndeclare(strict_types=1);\n",
            'README.md' => "# Exact deployment fixture\n",
            ...$extraEntries,
        ];
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $path => $contents) {
            self::assertTrue($zip->addFromString($path, $contents));
        }
        self::assertTrue($zip->close());
        $canonical = realpath($archive);
        self::assertIsString($canonical);
        $package = InspectedPackage::inspect($canonical);
        $reflection = new ReflectionClass(DoctrineExtensionManager::class);
        $manager = $reflection->newInstanceWithoutConstructor();
        $extensionRoot = $root . '/extensions';
        $publicRoot = $root . '/public/assets/extensions';
        $reflection->getProperty('contents')->setValue($manager, new ZipArchiveContentReader());
        $reflection->getProperty('extensionRoot')->setValue($manager, $extensionRoot);
        $reflection->getProperty('publicAssetRoot')->setValue($manager, $publicRoot);

        return [$manager, $reflection, $package, $root, $extensionRoot, $publicRoot];
    }

    /**
     * Require the independent package/deployment comparison to fail closed.
     *
     * @param   DoctrineExtensionManager                 $manager     Manager whose private verifier is invoked.
     * @param   ReflectionClass<DoctrineExtensionManager> $reflection  Reflection exposing the private verifier.
     * @param   InspectedPackage                         $package     Immutable package identity.
     * @param   string                                   $deployment Deployed tree offered as a replay candidate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertDeploymentMismatch(
        DoctrineExtensionManager $manager,
        ReflectionClass $reflection,
        InspectedPackage $package,
        string $deployment,
    ): void {
        try {
            $reflection->getMethod('verifiedDeployedTreeDigest')->invoke($manager, $package, $deployment);
            self::fail('A deployment that differed from its inspected package was accepted.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('does not match its inspected package', $failure->getMessage());
        }
    }

    /**
     * Allocate one canonical private directory and register it for teardown.
     *
     * @param   string  $label  Human-readable fixture label embedded in the path.
     *
     * @return  string  Canonical absolute root unique to this test process.
     *
     * @since   2.0.0
     */
    private function temporaryRoot(string $label): string
    {
        $root = sys_get_temp_dir() . '/kumwe-' . $label . '-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $canonical = realpath($root);
        self::assertIsString($canonical);
        $this->temporaryRoots[] = $canonical;

        return $canonical;
    }
}
