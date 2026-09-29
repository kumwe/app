<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateTimeImmutable;
use FilesystemIterator;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\BusinessSchema\Domain\SchemaPlan;
use Kumwe\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Extension\Toolchain\ComponentScaffolder;
use Kumwe\Extension\Toolchain\DeterministicPackageBuilder;
use Kumwe\Extension\Toolchain\PackageInspector;
use Kumwe\Extension\Toolchain\PackageSigner;
use Kumwe\Extension\Toolchain\ProtectedSigningKeyReader;
use Kumwe\Extension\Toolchain\ScaffoldRequest;
use Kumwe\Extension\Toolchain\StaticConformanceRunner;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Admit existing workflow documents through the released SDK and the real signed extension lifecycle.
 *
 * This test-only bridge changes ownership, namespaced handles and references, not the workflow model.
 * It retains every generated scaffold contribution. Explicit fixture policies are installed only after
 * operator-approved schema execution. Callers must use the returned fresh kernel and execution context.
 *
 * @since  2.0.0
 */
final readonly class SignedWorkflowExtensionFixture
{
    /**
     * Retain the admitted package and its fresh runtime until the caller disposes the fixture.
     *
     * @param  Environment                          $environment  Configuration used to reload the host.
     * @param  string                               $identifier   Signed extension owner.
     * @param  string                               $keyId        Scoped test signing identity.
     * @param  string                               $directory    Private generated source and archive directory.
     * @param  Container                            $container    Fresh kernel containing the active owner.
     * @param  ExecutionContext                     $context      Administrator issued by that kernel.
     * @param  array<string, EntityTypeDefinition>  $definitions  Active definitions keyed by original handle.
     * @param  string                               $sha256       Deterministic archive checksum.
     *
     * @since  2.0.0
     */
    private function __construct(
        private Environment $environment,
        public string $identifier,
        public string $keyId,
        public string $directory,
        public Container $container,
        public ExecutionContext $context,
        public array $definitions,
        public string $sha256,
    ) {
    }

    /**
     * Build twice, inspect, conform, sign, install disabled, activate and execute approved schema plans.
     *
     * @param   Environment                 $environment         Host configuration for this test database.
     * @param   string                      $identifier          Unique vendor/name for this fixture run.
     * @param   list<array<string, mixed>>  $documents           Existing site-owned workflow documents.
     * @param   list<string>                $translationLocales  Optional contributed content locales.
     *
     * @return  self  Active signed fixture with an original-to-extension definition map.
     *
     * @since   2.0.0
     */
    public static function install(
        Environment $environment,
        string $identifier,
        array $documents,
        array $translationLocales = [],
    ): self {
        $container = TestKernelFactory::create($environment);
        $context = TestKernelFactory::administratorContext($container);
        $manager = $container->get(ExtensionManager::class);
        $trust = $container->get(TrustStore::class);
        if (!$manager instanceof ExtensionManager || !$trust instanceof TrustStore) {
            throw new RuntimeException('The signed workflow fixture lifecycle is unavailable.');
        }
        $marker = bin2hex(random_bytes(8));
        $directory = sys_get_temp_dir() . '/kumwe-signed-workflow-' . $marker;
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('The signed workflow fixture directory cannot be created.');
        }
        $keyId = 'workflow-' . $marker;
        $trusted = false;
        $installed = false;
        try {
            $encoder = new DeterministicCanonicalEncoder();
            (new ComponentScaffolder($encoder))->scaffold(new ScaffoldRequest(
                $identifier,
                'WorkflowProof\\Generated' . $marker,
                $directory . '/source',
                'Signed workflow proof',
            ));
            $manifestPath = $directory . '/source/kumwe.json';
            $manifest = json_decode((string) file_get_contents($manifestPath), false, 64, JSON_THROW_ON_ERROR);
            if ($translationLocales !== []) {
                $manifest->contributions->content = (object) ['translation_groups' => [[
                    'group_id' => str_replace('/', '.', $identifier) . '.stories',
                    'locales' => $translationLocales,
                    'fallback_locale' => $translationLocales[0],
                ]]];
            }
            $handles = [];
            foreach ($documents as $document) {
                $old = $document['handle'];
                $handles[$old] = str_replace('/', '.', $identifier) . '.' . substr($old, strrpos($old, '.') + 1);
            }
            $definitions = [];
            foreach ($documents as $document) {
                $original = $document['handle'];
                $document = self::remap($document, $handles);
                $document['owner'] = ['type' => 'extension', 'identifier' => $identifier];
                $document['status'] = 'published';
                $document['definition_version'] = 1;
                $definitions[$original] = EntityTypeDefinition::fromArray($document);
                $manifest->contributions->business->definitions[] = $document;
            }
            file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
            $inspector = new PackageInspector($encoder);
            $builder = new DeterministicPackageBuilder($encoder, $inspector);
            $first = $builder->build($directory . '/source', $directory . '/package.zip');
            $second = $builder->build($directory . '/source', $directory . '/repeated.zip');
            $checksum = hash_file('sha256', $first->archive);
            if ($checksum !== hash_file('sha256', $second->archive)) {
                throw new RuntimeException('The signed workflow fixture is not reproducible.');
            }
            if (!(new StaticConformanceRunner($inspector))->run($first->archive)->conforms()) {
                throw new RuntimeException('The signed workflow fixture does not conform to the released SDK.');
            }
            $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
            $keyFile = $directory . '/signing.seed';
            file_put_contents($keyFile, bin2hex($seed), LOCK_EX);
            chmod($keyFile, 0600);
            $signature = (new PackageSigner(new ProtectedSigningKeyReader(), $inspector))->sign(
                $first->archive,
                $keyId,
                $keyFile,
            );
            unlink($keyFile);
            [$vendor, $name] = explode('/', $identifier, 2);
            $trust->add(
                $context,
                $keyId,
                base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed))),
                $vendor,
                $name,
                new DateTimeImmutable('+1 day'),
            );
            $trusted = true;
            $result = $manager->install($first->archive, $context, $keyId, $signature->base64Signature);
            $installed = true;
            if ($result['status'] !== 'disabled') {
                throw new RuntimeException('A signed workflow fixture must install disabled.');
            }
            $manager->activate($identifier, $context);
            $trust->synchronizeRuntimeMaterialization();
            $runtime = TestKernelFactory::create($environment);
            $editor = TestKernelFactory::administratorContext($runtime);
            $schemas = $runtime->get(BusinessSchemaService::class);
            if (!$schemas instanceof BusinessSchemaService) {
                throw new RuntimeException('The signed workflow schema service is unavailable.');
            }
            $ids = array_map(static fn (EntityTypeDefinition $definition): string => $definition->id, $definitions);
            $plans = array_filter(
                $schemas->plans($editor),
                static fn (SchemaPlan $plan): bool => in_array($plan->definitionId, $ids, true),
            );
            foreach ($plans as $plan) {
                if ($plan->status === SchemaPlanStatus::PendingApproval) {
                    $schemas->approve(
                        $editor,
                        $plan->id,
                        $plan->checksum(),
                        $plan->risk->requiresHighImpactAuthorization() ? $plan->checksum() : null,
                        null,
                    );
                }
            }
            // Connected graphs require each plan's independent approval before any graph DDL executes.
            foreach ($plans as $plan) {
                if ($schemas->plan($editor, $plan->id)->status === SchemaPlanStatus::Approved) {
                    $schemas->execute($editor, $plan->id);
                }
            }
            foreach ($definitions as $definition) {
                if ($schemas->installation($editor, $definition->id)?->status !== SchemaInstallationStatus::Active) {
                    throw new RuntimeException('A signed workflow definition has no active schema.');
                }
                NeutralBusinessFixture::grantRecordAccess($runtime, $editor, $definition);
            }

            return new self($environment, $identifier, $keyId, $directory, $runtime, $editor, $definitions, $checksum);
        } catch (Throwable $failure) {
            try {
                if ($installed) {
                    $manager->disable($identifier, $context);
                    $manager->uninstall($identifier, $context);
                }
                if ($trusted) {
                    $trust->revoke($context, $keyId, 'Failed workflow fixture cleanup.');
                }
            } finally {
                self::removeDirectory($directory);
            }
            throw $failure;
        }
    }

    /**
     * Withdraw the fixture through the public lifecycle, preserving its durable owned data and audit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function dispose(): void
    {
        $runtime = TestKernelFactory::create($this->environment);
        $context = TestKernelFactory::administratorContext($runtime);
        $manager = $runtime->get(ExtensionManager::class);
        $trust = $runtime->get(TrustStore::class);
        if (!$manager instanceof ExtensionManager || !$trust instanceof TrustStore) {
            throw new RuntimeException('The signed workflow cleanup lifecycle is unavailable.');
        }
        $manager->disable($this->identifier, $context);
        $manager->uninstall($this->identifier, $context);
        $trust->revoke($context, $this->keyId, 'Signed workflow fixture cleanup.');
        self::removeDirectory($this->directory);
    }

    /**
     * Rewrite exact definition-handle references without altering expressions, labels or local handles.
     *
     * @param   mixed                  $value    Definition node to rewrite recursively.
     * @param   array<string, string>  $handles  Original-to-extension handle mapping.
     *
     * @return  mixed  Definition node with owned reference targets rewritten.
     *
     * @since   2.0.0
     */
    private static function remap(mixed $value, array $handles): mixed
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $child): mixed => self::remap($child, $handles), $value);
        }

        return is_string($value) ? ($handles[$value] ?? $value) : $value;
    }

    /**
     * Remove only the private generated source/archive directory owned by this fixture.
     *
     * @param   string  $directory  Private temporary directory created by install().
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function removeDirectory(string $directory): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
