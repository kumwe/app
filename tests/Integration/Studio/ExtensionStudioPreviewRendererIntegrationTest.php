<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use DateTimeImmutable;
use FilesystemIterator;
use Joomla\DI\Container;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\App\Extension\Domain\PackageChecksum;
use Kumwe\App\Extension\Infrastructure\DoctrineExtensionManager;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Extension\Runtime\ContributedStudioPreviewBlockRendererRegistry;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeLoader;
use Kumwe\App\Extension\Runtime\TrustEnforcingStudioPreviewBlockRenderer;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Studio\Application\Preview\StudioCompositionMarkupRenderer;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingValues;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockReference;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockRendererRegistry;
use Kumwe\App\Tests\Support\TestKernelFactory;
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

#[CoversClass(ContributedStudioPreviewBlockRendererRegistry::class)]
#[CoversClass(StudioPreviewRendererContribution::class)]
#[CoversClass(TrustEnforcingStudioPreviewBlockRenderer::class)]
#[UsesClass(ActiveExtensionSet::class)]
#[UsesClass(DoctrineExtensionManager::class)]
#[UsesClass(ExtensionRuntimeLoader::class)]
/**
 * Proves schema-six preview code crosses the real signed extension lifecycle without manifest execution.
 *
 * @since  2.0.0
 */
final class ExtensionStudioPreviewRendererIntegrationTest extends TestCase
{
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
            $renderer = self::service($runtime, StudioCompositionMarkupRenderer::class);
            $rendererRegistry = self::service($runtime, StudioPreviewBlockRendererRegistry::class);
            $registries = self::service($runtime, ExtensionContributionRegistrySet::class);
            $definition = self::rendererDefinition($registries, $identifier);
            $namespace = str_replace('/', '.', $identifier);
            self::assertSame('1.0.0', $definition->runtimeVersion);
            self::assertSame($namespace . '/grid', $definition->blockType);
            self::assertSame('1.0.0', $definition->blockVersion);
            self::assertSame('grid-block-r1', $definition->blockRevision);
            self::assertSame($namespace . '.renderer.grid', $definition->renderer);
            self::assertSame($namespace . '/grid', $definition->previewCapability);
            self::assertSame('^1.0.0', $definition->previewCapabilityVersions);
            $exactReference = new StudioPreviewBlockReference(
                $namespace . '/grid',
                '1.0.0',
                'grid-block-r1',
            );
            self::assertTrue($rendererRegistry->supports($exactReference));

            $exact = self::document($namespace, '1.0.0', 'grid-block-r1');
            self::assertStringContainsString(
                '<section class="studio-preview-extension-grid" data-studio-preview-marker="marker-grid">',
                self::render($renderer, $exact),
            );
            self::assertStringContainsString(
                'Contributed grid: 3 columns (desktop)',
                self::render($renderer, $exact),
            );

            $wrongRevision = self::document($namespace, '1.0.0', 'grid-block-r2');
            self::assertFalse($rendererRegistry->supports(new StudioPreviewBlockReference(
                $namespace . '/grid',
                '1.0.0',
                'grid-block-r2',
            )));
            self::assertStringContainsString('class="studio-preview-unresolved"', self::render(
                $renderer,
                $wrongRevision,
            ));
            self::assertStringNotContainsString('studio-preview-extension-grid', self::render(
                $renderer,
                $wrongRevision,
            ));
            $wrongVersion = self::document($namespace, '2.0.0', 'grid-block-r1');
            self::assertStringContainsString('class="studio-preview-unresolved"', self::render(
                $renderer,
                $wrongVersion,
            ));

            $archives[] = $upgradeArchive = $this->fixturePackage($identifier, '1.1.0', false);
            $upgraded = $manager->install(
                $upgradeArchive,
                $context,
                $keyId,
                self::signature($upgradeArchive, $secretKey),
            );
            self::assertSame('1.1.0', $upgraded['installed_version'] ?? null);
            self::assertFalse($rendererRegistry->supports($exactReference));
            self::assertStringContainsString('class="studio-preview-unresolved"', self::render($renderer, $exact));
            if (($upgraded['status'] ?? null) !== 'active') {
                $manager->activate($identifier, $context);
            }
            $trust->synchronizeRuntimeMaterialization();
            $upgradedRuntime = TestKernelFactory::create($environment);
            $upgradedRenderer = self::service($upgradedRuntime, StudioCompositionMarkupRenderer::class);
            $upgradedRendererRegistry = self::service(
                $upgradedRuntime,
                StudioPreviewBlockRendererRegistry::class,
            );
            $upgradedRegistries = self::service($upgradedRuntime, ExtensionContributionRegistrySet::class);
            self::assertSame('1.1.0', self::rendererDefinition(
                $upgradedRegistries,
                $identifier,
            )->runtimeVersion);
            self::assertTrue($upgradedRendererRegistry->supports($exactReference));
            self::assertStringContainsString('studio-preview-extension-grid', self::render(
                $upgradedRenderer,
                $exact,
            ));

            $manager->disable($identifier, $context);
            self::assertFalse($upgradedRendererRegistry->supports($exactReference));
            self::assertStringContainsString('class="studio-preview-unresolved"', self::render(
                $upgradedRenderer,
                $exact,
            ));
            self::assertStringNotContainsString('Contributed grid', self::render($upgradedRenderer, $exact));

            $archives[] = $missingArchive = $this->fixturePackage($missingIdentifier, '1.0.0', true);
            $manager->install($missingArchive, $context, $keyId, self::signature($missingArchive, $secretKey));
            $installed[] = $missingIdentifier;
            $manager->activate($missingIdentifier, $context);
            $trust->synchronizeRuntimeMaterialization();
            $missingRuntime = TestKernelFactory::create($environment);
            $missingRegistries = self::service($missingRuntime, ExtensionContributionRegistrySet::class);
            self::assertNull(self::optionalRendererDefinition($missingRegistries, $missingIdentifier));
            $missingNamespace = str_replace('/', '.', $missingIdentifier);
            self::assertStringContainsString('class="studio-preview-unresolved"', self::render(
                self::service($missingRuntime, StudioCompositionMarkupRenderer::class),
                self::document($missingNamespace, '1.0.0', 'grid-block-r1'),
            ));
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
     * Build a unique package from the committed manifest-six compatibility fixture.
     *
     * @param   string  $identifier      Per-test extension identifier.
     * @param   string  $version         Exact package runtime version.
     * @param   bool    $missingService  Whether the provider deliberately omits the bound service.
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
        $root = dirname(__DIR__, 3) . '/tests/Fixtures/ExtensionApi/generations/manifest-6';
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
                $relative = substr($file->getPathname(), strlen($root) + 1);
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
                    $contents = str_replace('.renderer.grid\'', '.renderer.absent\'', $contents);
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
            (string) PackageChecksum::calculate($bytes),
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
     * @param   StudioCompositionMarkupRenderer  $renderer  Runtime-composed renderer.
     * @param   stdClass                         $document  Blueprint input.
     *
     * @return  string  Safe structural markup.
     *
     * @since   2.0.0
     */
    private static function render(StudioCompositionMarkupRenderer $renderer, stdClass $document): string
    {
        return $renderer->render(
            $document,
            ['marker-grid'],
            ['marker-grid' => 'grid-node'],
            new StudioPreviewBindingValues(new stdClass(), new stdClass()),
            'desktop',
        );
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
}
