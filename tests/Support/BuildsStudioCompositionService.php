<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateTimeImmutable;
use Kumwe\App\BusinessSurface\Presentation\Field\SdkFieldConfigurationAdmission;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Presentation\Application\SitePresentation;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Studio\Application\Composition\ContentBlueprintBindingStore;
use Kumwe\App\Studio\Application\Composition\StudioBuiltInThemeRelease;
use Kumwe\App\Studio\Application\Composition\StudioCompositionContributionCatalog;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioArtifactRepository;
use Kumwe\App\Studio\Application\Projection\ContentProjectionBindingRepository;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Application\Projection\RecordAuthorizedStudioContentFieldDisclosure;
use Kumwe\App\Studio\Application\Projection\StudioContentProjectionService;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioContentFieldBlockRenderer;
use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Kumwe\Content\Application\ContentModelRepository;
use Kumwe\Content\Application\ContentRepository;
use Kumwe\Content\Domain\ContentTypeDefinition;
use Kumwe\Content\Domain\JsonSchemaValidator;
use Kumwe\Content\Domain\SchemaCompatibilityChecker;
use Kumwe\Content\Workflow\Domain\Workflow;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;

/**
 * Builds the real `StudioContentCompositionService` over in-memory bindings, artifacts and site presentation.
 *
 * The authorized model projection, canonical artifact admission, contribution block locks, published-theme lock
 * and audit stay the service's own, so a machine adapter under test is proven against the same composition the
 * administrator screen reads and provisions. Only one Content type exists, `COMPOSITION_TYPE_ID` at version
 * four; `retheme()` changes the published presentation so every bound Blueprint meets its theme mismatch.
 *
 * @since  2.0.0
 */
trait BuildsStudioCompositionService
{
    /**
     * Content type every composition fixture projects.
     *
     * @var    string
     * @since  2.0.0
     */
    private static string $compositionTypeId = '018f22e2-7c8b-7ab0-8f3a-88e8026be901';

    /**
     * Blueprint binding stored by the last provisioning, or null before one.
     *
     * @var    ?ContentBlueprintBinding
     * @since  2.0.0
     */
    private ?ContentBlueprintBinding $compositionBinding = null;

    /**
     * Blueprint artifact stored by the last provisioning, or null before one.
     *
     * @var    ?StoredStudioArtifact
     * @since  2.0.0
     */
    private ?StoredStudioArtifact $compositionArtifact = null;

    /**
     * Public presentation the published theme reference is derived from.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    private array $compositionPresentation = [];

    /**
     * Build the service with an empty binding store, the default presentation and the given audit recorder.
     *
     * @param   RecordingAuditRecorder  $audit  Recorder the provisioning audit event reaches.
     *
     * @return  StudioContentCompositionService  Service under test.
     *
     * @since   2.0.0
     */
    private function compositionService(RecordingAuditRecorder $audit): StudioContentCompositionService
    {
        $this->compositionPresentation = SitePresentation::defaults();
        $now = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
        $clock = new MovableAuditClock($now);
        $models = $this->createStub(ContentModelRepository::class);
        $models->method('contentType')->willReturnCallback(
            static fn (SiteContext $site, string $id, ?int $version = null): ?ContentTypeDefinition
                => $id === self::$compositionTypeId && ($version === null || $version === 4)
                    ? new ContentTypeDefinition(
                        self::$compositionTypeId,
                        $site,
                        'article',
                        'Article',
                        ContentService::CORE_WORKFLOW_ID,
                        1,
                        [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => ['body' => ['type' => 'string']],
                            'required' => ['body'],
                        ],
                        4,
                        $now,
                        $now,
                    )
                    : null,
        );
        $bindings = $this->createStub(ContentProjectionBindingRepository::class);
        $bindings->method('blueprint')->willReturnCallback(
            fn (): ?ContentBlueprintBinding => $this->compositionBinding,
        );
        $bindingStore = $this->createStub(ContentBlueprintBindingStore::class);
        $bindingStore->method('add')->willReturnCallback(function (ContentBlueprintBinding $binding): void {
            $this->compositionBinding = $binding;
        });
        $artifacts = $this->createStub(StudioArtifactRepository::class);
        $artifacts->method('current')->willReturnCallback(fn (): ?StoredStudioArtifact => $this->compositionArtifact);
        $artifacts->method('store')->willReturnCallback(function (StoredStudioArtifact $artifact): bool {
            $this->compositionArtifact = $artifact;

            return true;
        });
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturnCallback(
            fn (): array => ['presentation' => $this->compositionPresentation, 'timezone' => []],
        );
        $registries = new ExtensionContributionRegistrySet(
            new DeterministicCanonicalEncoder(),
            new SdkFieldConfigurationAdmission(),
        );
        $transactions = new ImmediateTransactionManager();
        $authorization = AuthorizationContext::gateway();

        return new StudioContentCompositionService(
            new StudioContentProjectionService(
                new ContentModelService(
                    $models,
                    new JsonSchemaValidator(),
                    new SchemaCompatibilityChecker(),
                    $authorization,
                    AuthorizationContext::ownershipWriter(),
                    $audit,
                    $transactions,
                    $clock,
                ),
                new ContentService(
                    $this->createStub(ContentRepository::class),
                    $audit,
                    $transactions,
                    $clock,
                    new Workflow(),
                    $authorization,
                    AuthorizationContext::ownershipWriter(),
                ),
                $bindings,
                new ContentStudioProjector(
                    StudioDocumentSchemaRegistry::fromVendoredCorpus(),
                    new RecordAuthorizedStudioContentFieldDisclosure(),
                    new JsonSchemaValidator(),
                ),
            ),
            $bindings,
            $bindingStore,
            new StudioArtifactAdmission(StudioDocumentSchemaRegistry::fromVendoredCorpus()),
            $artifacts,
            $transactions,
            $audit,
            $clock,
            new StudioCompositionContributionCatalog(
                $registries,
                new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer()),
            ),
            new StudioPublishedTheme(
                $settings,
                new ActiveExtensionSet(new ExtensionContributionRegistrySet(
                    new DeterministicCanonicalEncoder(),
                    new SdkFieldConfigurationAdmission(),
                    withCore: false,
                )),
                new StudioBuiltInThemeRelease(str_repeat('a', 64)),
            ),
        );
    }

    /**
     * Change the published presentation, so every Blueprint provisioned before now is locked to another theme.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function retheme(): void
    {
        $this->compositionPresentation['active_scheme'] = 'ocean';
    }
}
