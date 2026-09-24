<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use DateTimeImmutable;
use FilesystemIterator;
use Kumwe\App\Administrator\Http\Handler\AdministratorExtensionsHandler;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Contribution\CanonicalManifestActivator;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\OwnedExtensionBindingRegistrar;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\Extension\Package\PackageChecksum;
use Kumwe\Extension\Package\PackageSignatureMessage;
use Kumwe\App\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\App\Extension\Infrastructure\Trust\DoctrineTrustStoreRepository;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeLoader;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Extension\Runtime\TrustEnforcingStudioPreviewBlockRenderer;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringCatalog;
use Kumwe\App\Studio\Application\Rendering\FragmentStudioPreviewBlockRenderer;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Render\BlockCoordinate;
use Kumwe\Producer\Render\CompositionRenderer;
use Kumwe\Producer\Render\RenderContext;
use Kumwe\Producer\Render\RenderException;
use Kumwe\Producer\Render\RenderPolicy;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use stdClass;
use Throwable;
use ZipArchive;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\Context\Value\ExecutionContext;

#[CoversClass(StudioBlockRendererRuntime::class)]
#[CoversClass(AdministratorExtensionsHandler::class)]
#[CoversClass(StudioPreviewRendererContribution::class)]
#[CoversClass(TrustEnforcingStudioPreviewBlockRenderer::class)]
#[CoversClass(ActiveExtensionSet::class)]
#[UsesClass(DoctrineExtensionManager::class)]
#[CoversClass(OwnedExtensionBindingRegistrar::class)]
#[UsesClass(CanonicalManifestActivator::class)]
#[CoversClass(ExtensionRuntimeLoader::class)]
/**
 * Proves schema-six preview code crosses the real signed extension lifecycle without manifest execution.
 *
 * @since  2.0.0
 */
final class ExtensionStudioPreviewRendererIntegrationTest extends TestCase
{
    /**
     * Contract-grammar preview marker for the fixture's one grid node (sha256 of `grid-node`).
     *
     * @var    string
     * @since  2.0.0
     */
    private const string GRID_MARKER =
        'studio.preview/node/d78336768c8cfc767454d3e2bafc54f62029c9d31aa281805075032763e0342b/0';

    /**
     * Install, activate and render the signed fixture, then prove every lifecycle fence fails closed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSignedOwnerRendererExecutesOnlyAtItsExactLiveCoordinates(): void
    {
        $environment = Environment::fromGlobals();
        $container = TestKernelFactory::create($environment);
        $manager = self::service($container, ExtensionManager::class);
        $trust = self::service($container, TrustStore::class);
        $context = TestKernelFactory::administratorContext($container);
        $marker = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $identifier = 'integration/studio-preview-' . $marker;
        $missingIdentifier = 'integration/studio-preview-missing-' . $marker;
        $keyId = 'integration.studio-preview.' . $marker;
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $archives = [];
        $installed = [];

        try {
            $trust->add(
                $context,
                $keyId,
                base64_encode(sodium_crypto_sign_publickey($keyPair)),
                'integration',
                '*',
                new DateTimeImmutable('+1 year'),
            );
            $archives[] = $baseArchive = $this->fixturePackage($identifier, '1.0.0', false);
            $manager->install($baseArchive, $context, $keyId, self::signature($baseArchive, $secretKey));
            $installed[] = $identifier;
            $manager->activate($identifier, $context);
            $trust->synchronizeRuntimeMaterialization();

            $runtime = TestKernelFactory::create($environment);
            $materialization = self::service($runtime, RuntimeMaterializationState::class);
            $compiler = self::service($runtime, ExtensionRuntimeMapCompiler::class);
            self::assertTrue(
                $compiler->matchesAuthority($materialization),
                'The loaded preview publication must still match database authority.',
            );
            self::assertTrue(
                $compiler->isCurrent($materialization),
                'The loaded preview publication must retain its live replica lease.',
            );
            $publication = $materialization->publication;
            self::assertNotNull($publication);
            $runtimeEntry = null;
            foreach ($publication->document['extensions'] ?? [] as $candidate) {
                if (is_array($candidate) && ($candidate['identifier'] ?? null) === $identifier) {
                    $runtimeEntry = $candidate;
                    break;
                }
            }
            self::assertIsArray($runtimeEntry);
            $runtimeTrust = self::service($runtime, TrustStore::class);
            $runtimeTrust->synchronizedLifecycle(
                static function () use ($runtimeTrust, $runtimeEntry): void {
                    $runtimeTrust->enforceRuntimeEntryTrust($runtimeEntry);
                },
            );
            $blocks = self::service($runtime, StudioBlockRendererRuntime::class);
            $registries = self::service($runtime, ExtensionContributionRegistrySet::class);
            self::assertExtensionsScreenRenders($runtime, $identifier);
            $definition = self::rendererDefinition($registries, $identifier);
            $namespace = str_replace('/', '.', $identifier);
            self::assertSame('1.0.0', $definition->runtimeVersion);
            self::assertSame($namespace . '/grid', $definition->blockType);
            self::assertSame('1.0.0', $definition->blockVersion);
            self::assertSame('grid-block-r1', $definition->blockRevision);
            self::assertSame($namespace . '/grid-preview', $definition->renderer);
            self::assertSame($namespace . '/grid', $definition->previewCapability);
            self::assertSame('^1.0.0', $definition->previewCapabilityVersions);
            $exactCoordinate = new BlockCoordinate(
                $namespace . '/grid',
                '1.0.0',
                'grid-block-r1',
            );
            $rendererRegistry = $blocks->registry();
            self::assertTrue($rendererRegistry->supports($exactCoordinate));
            self::assertTrue(self::rendererImplementation($registries, $identifier)->isAvailable());
            self::assertInstanceOf(
                FragmentStudioPreviewBlockRenderer::class,
                $rendererRegistry->rendererFor($exactCoordinate),
            );

            $exact = self::document($namespace, '1.0.0', 'grid-block-r1');
            self::assertStringContainsString(
                'data-studio-preview-marker="' . self::GRID_MARKER . '"',
                self::render($blocks, $exact),
            );
            self::assertStringContainsString(
                '<section class="studio-preview-extension-grid">',
                self::render($blocks, $exact),
            );
            self::assertStringContainsString(
                'Contributed grid: 3 columns',
                self::render($blocks, $exact),
            );

            $wrongRevision = self::document($namespace, '1.0.0', 'grid-block-r2');
            self::assertFalse($rendererRegistry->supports(new BlockCoordinate(
                $namespace . '/grid',
                '1.0.0',
                'grid-block-r2',
            )));
            self::assertRenderRefused($blocks, $wrongRevision);
            $wrongVersion = self::document($namespace, '2.0.0', 'grid-block-r1');
            self::assertRenderRefused($blocks, $wrongVersion);

            $archives[] = $upgradeArchive = $this->fixturePackage($identifier, '1.1.0', false);
            $upgraded = $manager->install(
                $upgradeArchive,
                $context,
                $keyId,
                self::signature($upgradeArchive, $secretKey),
            );
            self::assertSame('1.1.0', $upgraded['installed_version'] ?? null);
            self::assertFalse($blocks->registry()->supports($exactCoordinate));
            self::assertRenderRefused($blocks, $exact);
            if (($upgraded['status'] ?? null) !== 'active') {
                $manager->activate($identifier, $context);
            }
            $trust->synchronizeRuntimeMaterialization();
            $upgradedRuntime = TestKernelFactory::create($environment);
            $upgradedBlocks = self::service($upgradedRuntime, StudioBlockRendererRuntime::class);
            $upgradedRegistries = self::service($upgradedRuntime, ExtensionContributionRegistrySet::class);
            self::assertSame('1.1.0', self::rendererDefinition(
                $upgradedRegistries,
                $identifier,
            )->runtimeVersion);
            self::assertTrue($upgradedBlocks->registry()->supports($exactCoordinate));
            self::assertStringContainsString('studio-preview-extension-grid', self::render(
                $upgradedBlocks,
                $exact,
            ));

            $manager->disable($identifier, $context);
            self::assertFalse($upgradedBlocks->registry()->supports($exactCoordinate));
            self::assertRenderRefused($upgradedBlocks, $exact);

            $archives[] = $missingArchive = $this->fixturePackage($missingIdentifier, '1.0.0', true);
            $manager->install($missingArchive, $context, $keyId, self::signature($missingArchive, $secretKey));
            $installed[] = $missingIdentifier;
            $manager->activate($missingIdentifier, $context);
            $trust->synchronizeRuntimeMaterialization();
            // Binding is eager and mandatory: a provider whose declared renderer service is not the
            // SDK preview contract must fail the runtime load loudly instead of shipping a silent gap.
            try {
                TestKernelFactory::create($environment);
                self::fail('A bound preview service outside the SDK renderer contract must refuse to load.');
            } catch (LogicException $exception) {
                self::assertSame('The manifest-six preview renderer is unavailable.', $exception->getMessage());
            }
        } finally {
            foreach (array_reverse($installed) as $identifierToRemove) {
                try {
                    $manager->disable($identifierToRemove, $context);
                } catch (Throwable) {
                }
                try {
                    $manager->uninstall($identifierToRemove, $context);
                } catch (Throwable) {
                }
            }
            foreach ($archives as $archive) {
                if (is_file($archive)) {
                    unlink($archive);
                }
            }
        }
    }

    /**
     * Hold the lifecycle lock from a second database session and prove a trusted renderer never flaps.
     *
     * The lock is taken without waiting, and trust readers used to take it too: under concurrent load an
     * availability check refused by it answered "unavailable", the Studio registry lost the renderer, and
     * the contribution projection and generation changed for that one request. With the lock genuinely
     * held by another session — proven by a mutator being refused — availability, the renderer registry,
     * the rendered markup, the runtime generation and the contribution projection must all stay exactly
     * what they were with the lock free, on every repetition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTrustedPreviewAvailabilityProjectionAndGenerationHoldWhileTheLifecycleLockIsHeld(): void
    {
        $environment = Environment::fromGlobals();
        $container = TestKernelFactory::create($environment);
        $manager = self::service($container, ExtensionManager::class);
        $trust = self::service($container, TrustStore::class);
        $context = TestKernelFactory::administratorContext($container);
        $marker = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $identifier = 'integration/studio-contention-' . $marker;
        $keyId = 'integration.studio-contention.' . $marker;
        $keyPair = sodium_crypto_sign_keypair();
        $archives = [];
        $installed = [];

        try {
            $trust->add(
                $context,
                $keyId,
                base64_encode(sodium_crypto_sign_publickey($keyPair)),
                'integration',
                '*',
                new DateTimeImmutable('+1 year'),
            );
            $archives[] = $archive = $this->fixturePackage($identifier, '1.0.0', false);
            $manager->install(
                $archive,
                $context,
                $keyId,
                self::signature($archive, sodium_crypto_sign_secretkey($keyPair)),
            );
            $installed[] = $identifier;
            $manager->activate($identifier, $context);
            $trust->synchronizeRuntimeMaterialization();

            $runtime = TestKernelFactory::create($environment);
            $blocks = self::service($runtime, StudioBlockRendererRuntime::class);
            $catalog = self::service($runtime, ContentStudioAuthoringCatalog::class);
            $execution = self::service($runtime, ExtensionExecutionGate::class);
            $runtimeTrust = self::service($runtime, TrustStore::class);
            $renderer = self::rendererImplementation(
                self::service($runtime, ExtensionContributionRegistrySet::class),
                $identifier,
            );
            $namespace = str_replace('/', '.', $identifier);
            $coordinate = new BlockCoordinate($namespace . '/grid', '1.0.0', 'grid-block-r1');
            $document = self::document($namespace, '1.0.0', 'grid-block-r1');
            $baseline = $catalog->consistently(static fn (): array => self::contributionState($catalog));
            self::assertContains($namespace . '/grid', $baseline['locks']);
            self::assertTrue($renderer->isAvailable());

            [$mutatorRefused, $observations] = self::holdingLifecycleLock(
                static function () use (
                    $runtimeTrust,
                    $renderer,
                    $execution,
                    $blocks,
                    $catalog,
                    $coordinate,
                    $document,
                ): array {
                    $refused = false;
                    try {
                        $runtimeTrust->synchronizedLifecycle(static fn (): bool => true);
                    } catch (RuntimeException) {
                        $refused = true;
                    }
                    $seen = [];
                    for ($attempt = 0; $attempt < 5; ++$attempt) {
                        $seen[] = [
                            'available' => $renderer->isAvailable(),
                            'current' => $execution->isCurrent(),
                            'supported' => $blocks->registry()->supports($coordinate),
                            'state' => $catalog->consistently(static fn (): array => self::contributionState($catalog)),
                            'rendered' => self::rendersGrid($blocks, $document),
                        ];
                    }

                    return [$refused, $seen];
                },
            );

            self::assertTrue($mutatorRefused, 'The second session must genuinely hold the lifecycle lock.');
            foreach ($observations as $attempt => $seen) {
                self::assertTrue($seen['available'], sprintf('Attempt %d lost renderer availability.', $attempt));
                self::assertTrue($seen['current'], sprintf('Attempt %d lost the runtime generation.', $attempt));
                self::assertTrue($seen['supported'], sprintf('Attempt %d dropped the renderer.', $attempt));
                self::assertSame($baseline, $seen['state'], sprintf('Attempt %d changed the projection.', $attempt));
                self::assertTrue($seen['rendered'], sprintf('Attempt %d refused the trusted block.', $attempt));
            }
        } finally {
            self::remove($manager, $context, $installed, $archives);
        }
    }

    /**
     * Prove a revoked renderer and an untrusted renderer still never render while the lock is held.
     *
     * Readers no longer take the lifecycle lock, so contention must not open a path around trust either.
     * A key revoked (under the lock, as every mutator is) before a second session takes the lock leaves the
     * previously loaded runtime refusing. A signing key that lapses with no mutator and no new generation
     * is caught by the lock-free trust read itself, which quarantines the release while the other session
     * still holds the lock.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRevokedAndUntrustedRenderersNeverRenderWhileTheLifecycleLockIsHeld(): void
    {
        $environment = Environment::fromGlobals();
        $container = TestKernelFactory::create($environment);
        $manager = self::service($container, ExtensionManager::class);
        $trust = self::service($container, TrustStore::class);
        $context = TestKernelFactory::administratorContext($container);
        $marker = strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -10));
        $revokedIdentifier = 'integration/studio-revoked-' . $marker;
        $expiredIdentifier = 'integration/studio-expired-' . $marker;
        $revokedKey = 'integration.studio-revoked.' . $marker;
        $expiredKey = 'integration.studio-expired.' . $marker;
        $revokedPair = sodium_crypto_sign_keypair();
        $expiredPair = sodium_crypto_sign_keypair();
        $archives = [];
        $installed = [];

        try {
            foreach ([$revokedKey => $revokedPair, $expiredKey => $expiredPair] as $keyId => $pair) {
                $trust->add(
                    $context,
                    $keyId,
                    base64_encode(sodium_crypto_sign_publickey($pair)),
                    'integration',
                    '*',
                    new DateTimeImmutable('+1 year'),
                );
            }
            $archives[] = $archive = $this->fixturePackage($revokedIdentifier, '1.0.0', false);
            $manager->install(
                $archive,
                $context,
                $revokedKey,
                self::signature($archive, sodium_crypto_sign_secretkey($revokedPair)),
            );
            $installed[] = $revokedIdentifier;
            $manager->activate($revokedIdentifier, $context);
            $trust->synchronizeRuntimeMaterialization();
            $revokedRuntime = TestKernelFactory::create($environment);
            $revokedRenderer = self::rendererImplementation(
                self::service($revokedRuntime, ExtensionContributionRegistrySet::class),
                $revokedIdentifier,
            );
            self::assertTrue($revokedRenderer->isAvailable());
            self::assertSame(
                [$revokedIdentifier],
                $trust->emergencyRevoke($context, $revokedKey, 'Contention proof: the key is compromised.'),
            );

            self::holdingLifecycleLock(static function () use (
                $revokedRuntime,
                $revokedRenderer,
                $revokedIdentifier,
            ): void {
                $namespace = str_replace('/', '.', $revokedIdentifier);
                $blocks = self::service($revokedRuntime, StudioBlockRendererRuntime::class);
                self::assertFalse($revokedRenderer->isAvailable());
                self::assertFalse($blocks->registry()->supports(
                    new BlockCoordinate($namespace . '/grid', '1.0.0', 'grid-block-r1'),
                ));
                self::assertRenderRefused($blocks, self::document($namespace, '1.0.0', 'grid-block-r1'));
                self::assertNotContains(
                    $namespace . '/grid',
                    self::service($revokedRuntime, ContentStudioAuthoringCatalog::class)->consistently(
                        static fn (): array => self::contributionState(
                            self::service($revokedRuntime, ContentStudioAuthoringCatalog::class),
                        )['locks'],
                    ),
                );
            });

            $archives[] = $archive = $this->fixturePackage($expiredIdentifier, '1.0.0', false);
            $manager->install(
                $archive,
                $context,
                $expiredKey,
                self::signature($archive, sodium_crypto_sign_secretkey($expiredPair)),
            );
            $installed[] = $expiredIdentifier;
            $manager->activate($expiredIdentifier, $context);
            $trust->synchronizeRuntimeMaterialization();
            $expiredRuntime = TestKernelFactory::create($environment);
            $expiredRenderer = self::rendererImplementation(
                self::service($expiredRuntime, ExtensionContributionRegistrySet::class),
                $expiredIdentifier,
            );
            self::assertTrue($expiredRenderer->isAvailable());
            // The key lapses with no mutator and no new generation: only the lock-free trust read can see it.
            $database = self::service($container, Connection::class);
            $tables = self::service($container, TableNames::class);
            $database->executeStatement(
                sprintf('UPDATE %s SET expires_at = ? WHERE key_id = ?', $tables->quoted('extension_trust_keys')),
                [new DateTimeImmutable('-1 minute'), $expiredKey],
                [Types::DATETIME_IMMUTABLE, Types::STRING],
            );
            self::assertTrue(
                self::service($expiredRuntime, ExtensionExecutionGate::class)->isCurrent(),
                'A lapsed key publishes no generation, so the generation fence alone cannot refuse it.',
            );

            self::holdingLifecycleLock(static function () use (
                $expiredRuntime,
                $expiredRenderer,
                $expiredIdentifier,
            ): void {
                $namespace = str_replace('/', '.', $expiredIdentifier);
                $blocks = self::service($expiredRuntime, StudioBlockRendererRuntime::class);
                self::assertFalse($expiredRenderer->isAvailable());
                self::assertFalse($blocks->registry()->supports(
                    new BlockCoordinate($namespace . '/grid', '1.0.0', 'grid-block-r1'),
                ));
                self::assertRenderRefused($blocks, self::document($namespace, '1.0.0', 'grid-block-r1'));
            });
            $status = $database->fetchOne(
                sprintf('SELECT status FROM %s WHERE identifier = ?', $tables->quoted('extensions')),
                [$expiredIdentifier],
            );
            self::assertSame('quarantined', $status, 'The lock-free trust read must quarantine the release.');
        } finally {
            self::remove($manager, $context, $installed, $archives);
        }
    }

    /**
     * Build a unique package from the committed manifest-six compatibility fixture.
     *
     * @param   string  $identifier      Per-test extension identifier.
     * @param   string  $version         Exact package runtime version.
     * @param   bool    $missingService  Whether the bound service deliberately resolves outside the
     *          SDK renderer contract.
     *
     * @return  string  Absolute archive path.
     *
     * @throws  RuntimeException  When the fixture cannot be packaged.
     *
     * @since   2.0.0
     */
    private function fixturePackage(string $identifier, string $version, bool $missingService): string
    {
        $archive = tempnam(sys_get_temp_dir(), 'kumwe-studio-preview-extension-');
        if (!is_string($archive)) {
            throw new RuntimeException('The Studio preview fixture archive cannot be allocated.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The Studio preview fixture archive cannot be opened.');
        }
        $root = dirname(__DIR__, 3)
            . '/vendor/kumwe/extension-sdk/resources/fixtures/generations/manifest-6';
        $dotted = str_replace('/', '.', $identifier);
        $phpNamespace = 'IntegrationStudioPreview\\R' . substr(hash('sha256', $identifier), 0, 12);
        $jsonNamespace = str_replace('\\', '\\\\', $phpNamespace);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        try {
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if (!is_string($contents)) {
                    throw new RuntimeException('A Studio preview fixture file cannot be read.');
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if ($relative === 'src/Definitions.php') {
                    // Canonical constants are source-wrapped across adjacent literals. Join them before
                    // re-owning so a namespace split at a line boundary cannot survive inside signed bytes.
                    $joined = preg_replace("/'\\s*\\.\\s*'/", '', $contents);
                    if (!is_string($joined)) {
                        throw new RuntimeException('The Studio preview fixture definitions cannot be joined.');
                    }
                    $contents = $joined;
                }
                $contents = str_replace(
                    [
                        'KumweContract\\\\ManifestSix',
                        'KumweContract\\ManifestSix',
                        'kumwe/contract-manifest-six',
                        'kumwe.contract-manifest-six',
                    ],
                    [$jsonNamespace, $phpNamespace, $identifier, $dotted],
                    $contents,
                );
                if ($relative === 'src/Definitions.php') {
                    self::assertStringNotContainsString('kumwe.contract-manifest-six', $contents);
                    self::assertStringNotContainsString('kumwe/contract-manifest-six', $contents);
                }
                if ($relative === 'kumwe.json') {
                    $manifest = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
                    if (!is_array($manifest)) {
                        throw new RuntimeException('The Studio preview fixture manifest is invalid.');
                    }
                    $manifest['version'] = $version;
                    $requirements = $manifest['requires'] ?? null;
                    if (!is_array($requirements)) {
                        throw new RuntimeException('The Studio preview fixture requirements are invalid.');
                    }
                    $requirements['php'] = '^8.3.0';
                    $manifest['requires'] = $requirements;
                    $encoded = json_encode(
                        $manifest,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    );
                    $contents = $encoded . "\n";
                }
                if ($missingService && $relative === 'src/Provider.php') {
                    $contents = str_replace(
                        ': GridPreviewRenderer => new GridPreviewRenderer(),',
                        ': object => new \stdClass(),',
                        $contents,
                    );
                }
                if (!$zip->addFromString($relative, $contents)) {
                    throw new RuntimeException('A Studio preview fixture file cannot be packaged.');
                }
            }
        } finally {
            $zip->close();
        }

        return $archive;
    }

    /**
     * Sign one archive checksum with the fixture's private key.
     *
     * @param   string  $archive    Absolute package archive path.
     * @param   string  $secretKey  Sodium Ed25519 secret key bytes.
     *
     * @return  string  Base64 detached signature.
     *
     * @throws  RuntimeException  When the archive cannot be read.
     *
     * @since   2.0.0
     */
    private static function signature(string $archive, string $secretKey): string
    {
        if ($secretKey === '') {
            throw new RuntimeException('The Studio preview fixture signing key is unavailable.');
        }
        $bytes = file_get_contents($archive);
        if (!is_string($bytes)) {
            throw new RuntimeException('The Studio preview fixture archive cannot be signed.');
        }

        return base64_encode(sodium_crypto_sign_detached(
            PackageSignatureMessage::forChecksum(PackageChecksum::calculate($bytes)),
            $secretKey,
        ));
    }

    /**
     * Build one minimal Blueprint locked to a contributed grid definition.
     *
     * @param   string  $namespace  Dotted package namespace.
     * @param   string  $version    Node and dependency-lock version.
     * @param   string  $revision   Immutable dependency-lock revision.
     *
     * @return  stdClass  Minimal canonical preview input.
     *
     * @since   2.0.0
     */
    private static function document(string $namespace, string $version, string $revision): stdClass
    {
        $type = $namespace . '/grid';

        return (object) [
            'kind' => 'blueprint',
            'id' => $namespace . '/preview-proof',
            'revision' => 'blueprint-r1',
            'dependencyLock' => (object) ['blocks' => [(object) [
                'type' => $type,
                'version' => $version,
                'revision' => $revision,
            ]]],
            'roots' => [(object) [
                'id' => 'grid-node',
                'type' => $type,
                'version' => $version,
                'properties' => (object) ['columns' => 3, 'collapse' => 'stack'],
                'bindings' => new stdClass(),
                'slots' => (object) ['items' => []],
            ]],
        ];
    }

    /**
     * Render one-node preview input through the canonical structural composition path.
     *
     * @param   StudioBlockRendererRuntime  $runtime   Live runtime-composed Producer registry.
     * @param   stdClass                   $document  Blueprint input.
     *
     * @return  string  Safe structural markup.
     *
     * @since   2.0.0
     */
    private static function render(StudioBlockRendererRuntime $runtime, stdClass $document): string
    {
        return (new CompositionRenderer($runtime->registry()))->renderDocument(
            $document,
            new RenderContext(
                previewMarkerMap: [self::GRID_MARKER => 'grid-node'],
                policy: RenderPolicy::RequireRegistered,
            ),
        )->html;
    }

    /**
     * Report whether the canonical composition path renders the fixture grid, without throwing.
     *
     * @param   StudioBlockRendererRuntime  $runtime   Live runtime-composed Producer registry.
     * @param   stdClass                    $document  Blueprint input.
     *
     * @return  bool  True when the contributed grid's preview marker is in the markup; false when the
     *          composition was refused.
     *
     * @since   2.0.0
     */
    private static function rendersGrid(StudioBlockRendererRuntime $runtime, stdClass $document): bool
    {
        try {
            return str_contains(
                self::render($runtime, $document),
                'data-studio-preview-marker="' . self::GRID_MARKER . '"',
            );
        } catch (RenderException) {
            return false;
        }
    }

    /**
     * Require the canonical composition path to refuse the document at its current coordinates.
     *
     * @param   StudioBlockRendererRuntime  $runtime   Live runtime-composed Producer registry.
     * @param   stdClass                    $document  Blueprint input expected to be unregistered.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertRenderRefused(StudioBlockRendererRuntime $runtime, stdClass $document): void
    {
        try {
            self::render($runtime, $document);
            self::fail('An unregistered exact Producer coordinate must be refused.');
        } catch (RenderException $refused) {
            self::assertInstanceOf(RenderException::class, $refused);
        }
    }

    /**
     * Find the executable renderer definition owned by one active package.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Runtime contribution registry set.
     * @param   string                            $identifier  Expected package owner.
     *
     * @return  StudioPreviewRendererContribution  Exact live renderer definition.
     *
     * @since   2.0.0
     */
    private static function rendererDefinition(
        ExtensionContributionRegistrySet $registries,
        string $identifier,
    ): StudioPreviewRendererContribution {
        $definition = self::optionalRendererDefinition($registries, $identifier);
        self::assertInstanceOf(StudioPreviewRendererContribution::class, $definition);

        return $definition;
    }

    /**
     * Find the trust-enforcing executable registered by one active package.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Runtime contribution registry set.
     * @param   string                            $identifier  Expected package owner.
     *
     * @return  TrustEnforcingStudioPreviewBlockRenderer  Exact live trust-fenced SDK implementation.
     *
     * @since   2.0.0
     */
    private static function rendererImplementation(
        ExtensionContributionRegistrySet $registries,
        string $identifier,
    ): TrustEnforcingStudioPreviewBlockRenderer {
        foreach ($registries->studioPreviewRenderers()->executableEntries() as $entry) {
            if ($entry['owner']->identifier() !== $identifier) {
                continue;
            }
            $implementation = $entry['implementation'];
            self::assertInstanceOf(TrustEnforcingStudioPreviewBlockRenderer::class, $implementation);

            return $implementation;
        }

        self::fail('The exact live trust-fenced renderer implementation is unavailable.');
    }

    /**
     * Find an executable renderer definition without assuming the package activated one.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Runtime contribution registry set.
     * @param   string                            $identifier  Expected package owner.
     *
     * @return  StudioPreviewRendererContribution|null  Definition, or null for an inert binding.
     *
     * @since   2.0.0
     */
    private static function optionalRendererDefinition(
        ExtensionContributionRegistrySet $registries,
        string $identifier,
    ): ?StudioPreviewRendererContribution {
        foreach ($registries->studioPreviewRenderers()->executableEntries() as $entry) {
            if ($entry['owner']->identifier() === $identifier) {
                $definition = $entry['definition'];

                return $definition instanceof StudioPreviewRendererContribution ? $definition : null;
            }
        }

        return null;
    }

    /**
     * Run an operation while a second database session of this installation holds the lifecycle lock.
     *
     * The holder is a separate DBAL connection, so `GET_LOCK` on the MySQL family and the session advisory
     * lock on PostgreSQL are genuinely contended by every other connection the operation uses.
     *
     * @template T
     *
     * @param   callable(): T  $operation  Work to run while the other session holds the lock.
     *
     * @return  T  Whatever the operation returned.
     *
     * @since   2.0.0
     */
    private static function holdingLifecycleLock(callable $operation): mixed
    {
        $configuration = (new ConfigurationFactory())->create(Environment::fromGlobals())->database;
        $holder = (new DoctrineConnectionFactory($configuration))->create();
        try {
            return (new DoctrineTrustStoreRepository(
                $holder,
                new TableNames($holder, $configuration->tablePrefix),
            ))->synchronizedLifecycle($operation);
        } finally {
            $holder->close();
        }
    }

    /**
     * Capture the contribution projection a Studio session is authorized against.
     *
     * @param   ContentStudioAuthoringCatalog  $catalog  Live authoring catalogue.
     *
     * @return  array{generation: string, locks: list<mixed>, declaration: string}  Generation, renderable
     *          lock types and the canonical target declaration.
     *
     * @since   2.0.0
     */
    private static function contributionState(ContentStudioAuthoringCatalog $catalog): array
    {
        return [
            'generation' => $catalog->contributionGeneration(),
            'locks' => array_map(
                static fn (stdClass $lock): mixed => $lock->type ?? null,
                $catalog->renderableBlockLocks(),
            ),
            'declaration' => CanonicalJson::stringify($catalog->declaration()),
        ];
    }

    /**
     * Disable and uninstall every fixture package and delete every temporary archive, tolerating failure.
     *
     * @param   ExtensionManager  $manager    Lifecycle manager of the test installation.
     * @param   ExecutionContext  $context    Administrator context.
     * @param   list<string>      $installed  Installed identifiers, in install order.
     * @param   list<string>      $archives   Temporary archive paths.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function remove(
        ExtensionManager $manager,
        ExecutionContext $context,
        array $installed,
        array $archives,
    ): void {
        foreach (array_reverse($installed) as $identifier) {
            try {
                $manager->disable($identifier, $context);
            } catch (Throwable) {
            }
            try {
                $manager->uninstall($identifier, $context);
            } catch (Throwable) {
            }
        }
        foreach ($archives as $archive) {
            if (is_file($archive)) {
                unlink($archive);
            }
        }
    }

    /**
     * Resolve and assert one typed service from the real kernel container.
     *
     * @template T of object
     *
     * @param   Container        $container  Real application container.
     * @param   class-string<T>  $class      Requested service contract.
     *
     * @return  T  Typed service.
     *
     * @since   2.0.0
     */
    private static function service(Container $container, string $class): object
    {
        $service = $container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * Prove the administrator extensions screen renders a composition-only extension's diagnostics.
     *
     * A manifest that declares only a Studio composition contributes no administrator section to the
     * canonical SDK graph; the screen still reads every section, so the live projection must fill it.
     *
     * @param   Container  $runtime     Fresh kernel that loaded the renderer extension.
     * @param   string     $identifier  Installed extension whose row must render.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertExtensionsScreenRenders(Container $runtime, string $identifier): void
    {
        $context = TestKernelFactory::administratorContext($runtime);
        $principal = $context->principal();
        self::assertInstanceOf(AuthenticatedPrincipal::class, $principal);
        $manager = self::service($runtime, ExtensionManager::class);
        $row = null;
        foreach ($manager->installed($context) as $candidate) {
            if (is_array($candidate) && ($candidate['identifier'] ?? null) === $identifier) {
                $row = $candidate;
            }
        }
        self::assertIsArray($row);
        self::assertIsArray($row['contributions']['composition'] ?? null);
        self::assertSame([], $row['contributions']['administrator']['routes']);
        $handler = self::service($runtime, AdministratorExtensionsHandler::class);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator/extensions')
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, new AdministratorSession(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb39a',
                $principal,
                'preview-renderer-csrf',
                new DateTimeImmutable('+1 hour'),
            ));

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString($identifier, (string) $response->getBody());
    }
}
