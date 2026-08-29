<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Development;

use Closure;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Extension\Runtime\RestrictedExtensionContainer;
use Kumwe\Extension\Manifest\ExtensionManifest;
use Kumwe\Extension\Package\PackageChecksum;
use Kumwe\Extension\Package\PackageSignature;
use Kumwe\Extension\Package\PackageSignatureMessage;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordPage;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordReader;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordReadRequest;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Extension\Toolchain\DeterministicPackageBuilder;
use Kumwe\Extension\Toolchain\PackageInspector;
use Kumwe\Extension\Toolchain\PackageSigner;
use Kumwe\Extension\Toolchain\ProtectedSigningKeyReader;
use Kumwe\Extension\Toolchain\StaticConformanceRunner;
use KumweExample\AssetInspection\Provider;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversClass(Provider::class)]
/**
 * Proves the installable asset-inspection package is canonical SDK code with one signed declaration graph.
 *
 * @since  2.0.0
 */
final class AssetInspectionExampleTest extends TestCase
{
    /**
     * Example-namespace autoloader installed only while this test class runs.
     *
     * @var    ?Closure(string):void
     * @since  2.0.0
     */
    private static ?Closure $exampleLoader = null;

    /**
     * Register the package's own PSR-4 mapping without adding examples to the application autoloader.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $source = self::sourceDirectory() . '/src/';
        self::$exampleLoader = static function (string $class) use ($source): void {
            $prefix = 'KumweExample\\AssetInspection\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $file = $source . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        };
        spl_autoload_register(self::$exampleLoader, true, true);
    }

    /**
     * Remove the temporary example autoloader after this class completes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$exampleLoader instanceof Closure) {
            spl_autoload_unregister(self::$exampleLoader);
            self::$exampleLoader = null;
        }
        parent::tearDownAfterClass();
    }

    /**
     * Reject application namespaces and retired code-side declaration phases in the author package.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPackageSourceDependsOnlyOnCanonicalAuthorApis(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::sourceDirectory() . '/src'));
        $inspected = 0;
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            ++$inspected;
            $source = (string) file_get_contents($file->getPathname());
            self::assertStringNotContainsString('Kumwe\\App\\', $source, $file->getPathname());
            self::assertStringNotContainsString('function contribute(', $source, $file->getPathname());
        }
        self::assertGreaterThan(0, $inspected);
    }

    /**
     * Parse the signed manifest once and prove it is the complete executable declaration authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalManifestCarriesTheCompleteExecutableInventory(): void
    {
        $manifest = self::manifest();
        $requirements = $manifest->contributions()->executableBindingRequirements()->toArray();
        ksort($requirements, SORT_STRING);

        self::assertSame('kumwe/asset-inspection-example', $manifest->identifier()->value());
        self::assertSame(4, $manifest->schemaVersion());
        self::assertSame([
            'administrator_route' => ['kumwe.asset-inspection-example.administrator.index'],
            'custom_business_view_handler' => [
                'kumwe.asset-inspection-example.views.inspection-risk-summary',
            ],
            'domain_listener' => ['kumwe.asset-inspection-example.inspection-mutation-validator'],
            'event_consumer' => ['kumwe.asset-inspection-example.inspection-mutation-indexer'],
            'job_handler' => ['kumwe.asset-inspection-example.review-overdue'],
            'portal_route' => ['kumwe.asset-inspection-example.portal.status'],
            'projection' => ['kumwe.asset-inspection-example.inspection-activity'],
        ], $requirements);
    }

    /**
     * Activate declarations and exact executable bindings through the real App host registries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProviderActivatesAndWithdrawsThroughCanonicalSdkBindings(): void
    {
        $manifest = self::manifest();
        $provider = new Provider();
        $container = new RestrictedExtensionContainer($manifest->identifier()->value(), [
            BusinessRecordReader::class => new AssetInspectionRecordReaderProbe(),
        ]);
        $provider->register($container);
        $registries = new ExtensionContributionRegistrySet();
        $active = new ActiveExtensionSet($registries);
        $active->add(
            $manifest->identifier()->value(),
            $provider,
            $container,
            $manifest->contributions(),
        );
        $active->activate();

        $owner = ContributionOwner::extension($manifest->identifier()->value());
        $inventory = $registries->inventory($owner);
        self::assertCount(1, $inventory['administrator']['routes']);
        self::assertCount(1, $inventory['portal']['routes']);
        self::assertCount(1, $inventory['business']['view_handlers']);
        self::assertCount(1, $inventory['integration']['domain_listeners']);
        self::assertCount(1, $inventory['integration']['consumers']);
        self::assertCount(1, $inventory['integration']['jobs']);
        self::assertCount(1, $inventory['integration']['projections']);

        $active->withdrawAll();
        self::assertSame([], $registries->inventory($owner)['administrator']['routes']);
        self::assertSame([], $registries->inventory($owner)['integration']['jobs']);
    }

    /**
     * Build two identical packages, run code-free conformance, and verify the domain-separated signature.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExampleBuildsReproduciblyAndProducesAVerifiableSignature(): void
    {
        $temporary = sys_get_temp_dir() . '/kumwe-asset-inspection-example-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($temporary, 0700));
        $firstPath = $temporary . '/first.zip';
        $secondPath = $temporary . '/second.zip';
        $keyPath = $temporary . '/release.seed';
        try {
            $inspector = new PackageInspector();
            $builder = new DeterministicPackageBuilder($inspector);
            $first = $builder->build(self::sourceDirectory(), $firstPath);
            $second = $builder->build(self::sourceDirectory(), $secondPath);
            self::assertSame(
                (string) $first->inspection->package->checksum,
                (string) $second->inspection->package->checksum,
            );
            self::assertTrue((new StaticConformanceRunner($inspector))->run($first->archive)->conforms());

            $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
            self::assertSame(64, file_put_contents($keyPath, bin2hex($seed), LOCK_EX));
            self::assertTrue(chmod($keyPath, 0600));
            $signature = (new PackageSigner(new ProtectedSigningKeyReader(), $inspector))->sign(
                $first->archive,
                'asset-inspection-example-test',
                $keyPath,
            );
            $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
            self::assertTrue(sodium_crypto_sign_verify_detached(
                PackageSignature::ed25519($signature->keyId, $signature->base64Signature)->bytes(),
                PackageSignatureMessage::forChecksum(PackageChecksum::sha256($signature->packageSha256)),
                $publicKey,
            ));
        } finally {
            foreach ([$firstPath, $secondPath, $keyPath] as $path) {
                if (is_file($path) && !is_link($path)) {
                    unlink($path);
                }
            }
            if (is_dir($temporary) && !is_link($temporary)) {
                rmdir($temporary);
            }
        }
    }

    /**
     * Parse the committed package manifest through the canonical SDK parser.
     *
     * @return  ExtensionManifest  Structurally validated package manifest.
     *
     * @since   2.0.0
     */
    private static function manifest(): ExtensionManifest
    {
        return ExtensionManifest::fromJson((string) file_get_contents(self::sourceDirectory() . '/kumwe.json'));
    }

    /**
     * Locate the committed author package.
     *
     * @return  string  Absolute package source directory.
     *
     * @since   2.0.0
     */
    private static function sourceDirectory(): string
    {
        return dirname(__DIR__, 4) . '/examples/extensions/asset-inspection';
    }
}

/**
 * Host-service probe that proves provider composition does not require App record types.
 *
 * @since  2.0.0
 */
final readonly class AssetInspectionRecordReaderProbe implements BusinessRecordReader
{
    /**
     * Refuse reads because the activation proof binds but never executes the custom view.
     *
     * @param   BusinessRecordReadRequest  $query  Canonical request that would cross the host policy boundary.
     *
     * @return  BusinessRecordPage  No page is produced by this composition-only probe.
     *
     * @throws  LogicException  Always; record execution is outside this activation test.
     *
     * @since   2.0.0
     */
    public function readPage(BusinessRecordReadRequest $query): BusinessRecordPage
    {
        throw new LogicException('The activation probe does not execute business-record reads.');
    }
}
