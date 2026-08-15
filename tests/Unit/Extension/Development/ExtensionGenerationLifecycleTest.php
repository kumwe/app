<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Development;

use FilesystemIterator;
use Kumwe\CMS\Extension\Application\Install\AtomicInstallPlan;
use Kumwe\CMS\Extension\Application\Install\InstallState;
use Kumwe\CMS\Extension\Application\Package\PackageSafetyPolicy;
use Kumwe\CMS\Extension\Application\Trust\PackageTrustPolicy;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Development\DeterministicPackageBuilder;
use Kumwe\CMS\Extension\Development\PackageInspector;
use Kumwe\CMS\Extension\Development\PackageSigner;
use Kumwe\CMS\Extension\Development\ProtectedSigningKeyReader;
use Kumwe\CMS\Extension\Development\SignatureDocument;
use Kumwe\CMS\Extension\Development\StaticConformanceRunner;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Extension\Domain\ExtensionRecord;
use Kumwe\CMS\Extension\Domain\ExtensionStatus;
use Kumwe\CMS\Extension\Domain\PackageSignature;
use Kumwe\CMS\Extension\Infrastructure\Package\ZipArchiveReader;
use Kumwe\CMS\Extension\Infrastructure\Trust\SodiumEd25519Verifier;
use Kumwe\CMS\Extension\Runtime\ActiveExtensionSet;
use Kumwe\CMS\Extension\Runtime\RestrictedExtensionContainer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversNothing]
/**
 * Drives the signed compatibility package of every promised generation through the whole lifecycle.
 *
 * One package per manifest generation is built reproducibly from committed source, signed with the
 * fixture key `docs/extension-contract/generations.json` publishes, admitted through the production
 * trust policy, installed, activated, upgraded, disabled, reactivated and uninstalled. At each step the
 * package's contributed surface is compared against the inventory that document says the generation
 * promises, so a change to what a generation gives a package fails here rather than in an author's
 * build.
 *
 * The lifecycle is the platform's, not an invented one: `upgrade()` deliberately leaves the record
 * disabled so an operator re-activates against the new code, and the test asserts that rather than
 * working around it.
 *
 * @since  2.0.0
 */
final class ExtensionGenerationLifecycleTest extends TestCase
{
    /**
     * Absolute path to this run's private working directory.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $workspace;

    /**
     * Create the private working directory each generation builds its packages in.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir() . '/kumwe-generation-lifecycle-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->workspace, 0700));
    }

    /**
     * Remove the working directory and everything built inside it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        if (is_dir($this->workspace) && !is_link($this->workspace)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->workspace, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                if (!$entry instanceof SplFileInfo) {
                    continue;
                }
                if ($entry->isDir() && !$entry->isLink()) {
                    rmdir($entry->getPathname());
                    continue;
                }
                unlink($entry->getPathname());
            }
            rmdir($this->workspace);
        }
        parent::tearDown();
    }

    #[DataProvider('promisedGenerations')]
    /**
     * Run one promised generation's signed package through install to uninstall.
     *
     * @param   string  $generation  Identifier of the manifest generation under test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPromisedGenerationCompletesItsDeclaredLifecycle(string $generation): void
    {
        $contract = self::generation($generation);
        $fixture = $contract['fixture'];
        self::assertIsArray($fixture);
        $package = $fixture['package'];
        self::assertIsString($package);
        $source = dirname(__DIR__, 4) . '/' . $package;
        $this->loadPackageClasses($source);

        $manifestJson = file_get_contents($source . '/kumwe.json');
        self::assertIsString($manifestJson);
        self::assertSame($fixture['manifest_sha256'], hash('sha256', $manifestJson));
        $manifest = ExtensionManifest::fromJson($manifestJson);
        self::assertSame($contract['schema'], $manifest->schemaVersion());
        self::assertSame($fixture['identifier'], $manifest->identifier()->value());

        $expected = $fixture['contributions'];
        self::assertIsArray($expected);

        // Install: the package is built reproducibly, proven safe, signed, and admitted by the trust policy.
        $installed = $this->buildSignAndAdmit($source, 'base', $manifest);
        $plan = new AtomicInstallPlan(
            '0192f4a1-6d3c-7c21-9f0a-3f1c6b5d4e21',
            $manifest->identifier(),
            $manifest->version(),
            $installed,
            null,
        );
        $plan->start();
        while (($action = $plan->nextAction()) !== null) {
            $plan->complete($action);
        }
        $plan->commit();
        self::assertSame(InstallState::Committed, $plan->state());
        self::assertCount(9, $plan->completedActions());

        $record = ExtensionRecord::install($manifest);
        self::assertSame(ExtensionStatus::Disabled, $record->status());

        // Activate: the provider contributes, and the runtime surface matches what the generation promises.
        $record->activate();
        self::assertSame(ExtensionStatus::Active, $record->status());
        $activated = $this->materialize($manifest);
        self::assertSame($expected, $this->counts($activated));

        // Upgrade: a newer signed package replaces the release and leaves the record awaiting re-activation.
        $upgradeManifest = $this->upgradeSource($source, $manifestJson);
        $this->buildSignAndAdmit($this->workspace . '/upgrade', 'upgrade', $upgradeManifest);
        $record->upgrade($upgradeManifest);
        self::assertSame('1.1.0', (string) $record->installedVersion());
        self::assertSame(ExtensionStatus::Disabled, $record->status());
        $record->activate();
        $upgraded = $this->materialize($upgradeManifest);
        self::assertSame($activated, $upgraded, 'The upgrade changed the surface the generation promises.');

        // Disable: the contributed surface is withdrawn and the files stay where they are.
        $record->disable();
        self::assertSame(ExtensionStatus::Disabled, $record->status());

        // Reactivate: the same surface comes back, entry for entry.
        $record->activate();
        self::assertSame(ExtensionStatus::Active, $record->status());
        self::assertSame($activated, $this->materialize($upgradeManifest));

        // Uninstall: nothing the package owned survives in the shared registries.
        $registries = new ExtensionContributionRegistrySet();
        $owner = ContributionOwner::extension($manifest->identifier()->value());
        $registries->remove($owner);
        self::assertSame([], $this->counts($registries->inventory($owner)));
    }

    /**
     * Enumerate the manifest generations the frozen contract still promises.
     *
     * @return  iterable<string, array{string}>  Generation identifier, keyed by itself.
     *
     * @since   2.0.0
     */
    public static function promisedGenerations(): iterable
    {
        foreach (self::contract()['manifest_generations'] as $entry) {
            self::assertIsArray($entry);
            $id = $entry['id'];
            self::assertIsString($id);
            yield $id => [$id];
        }
    }

    /**
     * Build one package deterministically, prove it conforms, sign it, and admit it through the trust policy.
     *
     * @param   string             $source    Absolute path to the package source tree.
     * @param   string             $label     Short name distinguishing this build's artifacts.
     * @param   ExtensionManifest  $manifest  Parsed manifest of the tree being built.
     *
     * @return  \Kumwe\CMS\Extension\Domain\PackageChecksum  Digest of the admitted package.
     *
     * @since   2.0.0
     */
    private function buildSignAndAdmit(
        string $source,
        string $label,
        ExtensionManifest $manifest,
    ): \Kumwe\CMS\Extension\Domain\PackageChecksum {
        $inspector = new PackageInspector(new ZipArchiveReader(), new PackageSafetyPolicy());
        $artifacts = $this->workspace . '/artifacts';
        if (!is_dir($artifacts)) {
            self::assertTrue(mkdir($artifacts, 0700));
        }
        $archive = $artifacts . '/' . $label . '.zip';
        $repeat = $artifacts . '/' . $label . '-repeat.zip';
        $builder = new DeterministicPackageBuilder($inspector);
        $built = $builder->build($source, $archive);
        $again = $builder->build($source, $repeat);
        self::assertSame((string) $built->inspection->checksum, (string) $again->inspection->checksum);
        self::assertSame(
            $manifest->identifier()->value(),
            $built->inspection->manifest->identifier()->value(),
        );
        self::assertTrue((new StaticConformanceRunner($inspector))->run($archive)->conforms());

        $signing = self::contract()['signing'];
        self::assertIsArray($signing);
        $keyId = $signing['identifier'];
        self::assertIsString($keyId);
        self::assertSame('ed25519', $signing['algorithm'] ?? null);
        self::assertSame('sha256(seed_stem, raw)', $signing['seed_derivation'] ?? null);
        $stem = $signing['seed_stem'];
        self::assertIsString($stem);
        $keyFile = $artifacts . '/' . $label . '.seed';
        $seed = hash('sha256', $stem, true);
        self::assertIsInt(file_put_contents($keyFile, bin2hex($seed), LOCK_EX));
        self::assertTrue(chmod($keyFile, 0600));
        $signature = (new PackageSigner(new ProtectedSigningKeyReader(), $inspector))
            ->sign($archive, $keyId, $keyFile);
        self::assertSame(SignatureDocument::FORMAT, $signature->toArray()['format']);
        self::assertSame((string) $built->inspection->checksum, $signature->packageSha256);

        $publicKey = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)));
        $policy = new PackageTrustPolicy(new SodiumEd25519Verifier([$keyId => $publicKey]), [$keyId]);
        $policy->assertTrusted(
            $built->inspection->checksum,
            PackageSignature::ed25519($signature->keyId, $signature->base64Signature),
            false,
        );

        return $built->inspection->checksum;
    }

    /**
     * Copy the committed source into the workspace with its version raised, producing an upgrade package.
     *
     * @param   string  $source        Absolute path to the committed package source tree.
     * @param   string  $manifestJson  The committed manifest bytes.
     *
     * @return  ExtensionManifest  Parsed manifest of the upgrade tree.
     *
     * @since   2.0.0
     */
    private function upgradeSource(string $source, string $manifestJson): ExtensionManifest
    {
        $target = $this->workspace . '/upgrade';
        self::assertTrue(mkdir($target, 0700));
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || !$entry->isFile()) {
                continue;
            }
            $relative = substr($entry->getPathname(), strlen($source) + 1);
            $destination = $target . '/' . $relative;
            $parent = dirname($destination);
            if (!is_dir($parent)) {
                self::assertTrue(mkdir($parent, 0700, true));
            }
            self::assertTrue(copy($entry->getPathname(), $destination));
        }
        $upgraded = str_replace('"version": "1.0.0"', '"version": "1.1.0"', $manifestJson);
        self::assertNotSame($manifestJson, $upgraded, 'The generation fixture must ship version 1.0.0.');
        self::assertIsInt(file_put_contents($target . '/kumwe.json', $upgraded, LOCK_EX));

        return ExtensionManifest::fromJson($upgraded);
    }

    /**
     * Load the package's own classes once, so the provider named by the manifest can be instantiated.
     *
     * Only the committed tree is loaded. The upgrade copy carries the same class names and is built,
     * signed and admitted as bytes rather than executed, which is what an installed release does too.
     *
     * @param   string  $source  Absolute path to the committed package source tree.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function loadPackageClasses(string $source): void
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
    }

    /**
     * Materialize the extension into a fresh runtime map and report the surface it contributed.
     *
     * @param   ExtensionManifest  $manifest  Manifest of the release being materialized.
     *
     * @return  array<string, mixed>  The contribution inventory for this package's owner.
     *
     * @since   2.0.0
     */
    private function materialize(ExtensionManifest $manifest): array
    {
        $registries = new ExtensionContributionRegistrySet();
        $active = new ActiveExtensionSet($registries);
        $identifier = $manifest->identifier()->value();
        $providerClass = $manifest->serviceProvider();
        self::assertTrue(class_exists($providerClass), sprintf('Provider %s is unavailable.', $providerClass));
        $provider = new $providerClass();
        $container = new RestrictedExtensionContainer($identifier, []);
        $provider->register($container);
        $active->add($identifier, $provider, $container, $manifest->contributions(), $manifest->schemaVersion() >= 2);
        $active->contribute();
        $active->boot();
        self::assertSame(1, $active->count());

        return $registries->inventory(ContributionOwner::extension($identifier));
    }

    /**
     * Reduce a contribution inventory to the non-empty surfaces and how many entries each holds.
     *
     * @param   array<string, mixed>  $inventory  Inventory as the registries report it.
     *
     * @return  array<string, int>  Entry count per dotted surface key; surfaces with none are omitted.
     *
     * @since   2.0.0
     */
    private function counts(array $inventory): array
    {
        $counts = [];
        foreach ($inventory as $key => $value) {
            self::assertIsArray($value);
            if (array_is_list($value)) {
                if ($value !== []) {
                    $counts[$key] = count($value);
                }
                continue;
            }
            foreach ($value as $nested => $entries) {
                self::assertIsArray($entries);
                if ($entries !== []) {
                    $counts[$key . '.' . $nested] = count($entries);
                }
            }
        }

        return $counts;
    }

    /**
     * Read one manifest generation's entry from the frozen contract.
     *
     * @param   string  $generation  Generation identifier.
     *
     * @return  array<string, mixed>  The generation entry.
     *
     * @since   2.0.0
     */
    private static function generation(string $generation): array
    {
        foreach (self::contract()['manifest_generations'] as $entry) {
            self::assertIsArray($entry);
            if (($entry['id'] ?? null) === $generation) {
                return $entry;
            }
        }
        self::fail(sprintf('The frozen contract does not declare generation %s.', $generation));
    }

    /**
     * Load the frozen generation contract.
     *
     * @return  array<string, mixed>  Decoded contract document.
     *
     * @since   2.0.0
     */
    private static function contract(): array
    {
        $json = file_get_contents(dirname(__DIR__, 4) . '/docs/extension-contract/generations.json');
        self::assertIsString($json);
        $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame('kumwe-extension-contract-generations-v1', $document['format'] ?? null);
        self::assertIsArray($document['manifest_generations'] ?? null);

        return $document;
    }
}
