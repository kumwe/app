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
use Kumwe\App\Studio\Application\Composition\EntryCompositionOverrideStore;
use Kumwe\App\Studio\Application\Composition\StudioBuiltInThemeRelease;
use Kumwe\App\Studio\Application\Composition\StudioCompositionContributionCatalog;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Studio\Application\Composition\StudioPublishedCompositionGuard;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioArtifactRepository;
use Kumwe\App\Studio\Application\Host\StudioPersistenceRace;
use Kumwe\App\Studio\Application\Projection\ContentProjectionBindingRepository;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Application\Projection\RecordAuthorizedStudioContentFieldDisclosure;
use Kumwe\App\Studio\Application\Projection\StudioContentProjectionService;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioContentFieldBlockRenderer;
use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Kumwe\App\Studio\Domain\Projection\EntryCompositionOverrides;
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
 * Item layout Blueprints (App ADR 0025) keep their own in-memory history and head, the entry override record
 * moves by compare-and-set, and the real publication guard checks every item layout the service admits.
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
     * Item layout Blueprint revisions stored so far, in storage order.
     *
     * @var    list<StoredStudioArtifact>
     * @since  2.0.0
     */
    private array $compositionItemArtifacts = [];

    /**
     * The one entry override record the binding store holds, or null before one is pinned.
     *
     * @var    ?EntryCompositionOverrides
     * @since  2.0.0
     */
    private ?EntryCompositionOverrides $compositionOverrides = null;

    /**
     * Whether the item layout Blueprint head refuses every store, as a concurrent writer that moved it would.
     *
     * @var    bool
     * @since  2.0.0
     */
    private bool $compositionItemHeadMoved = false;

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
        $bindings->method('overrides')->willReturnCallback(
            fn (): ?EntryCompositionOverrides => $this->compositionOverrides,
        );
        $bindingStore = $this->createStub(ContentBlueprintBindingStore::class);
        $bindingStore->method('add')->willReturnCallback(function (ContentBlueprintBinding $binding): void {
            $this->compositionBinding = $binding;
        });
        $overrideStore = $this->createStub(EntryCompositionOverrideStore::class);
        $overrideStore->method('pin')->willReturnCallback(
            function (EntryCompositionOverrides $next, ?int $expected): void {
                if ($this->compositionOverrides?->revision !== $expected) {
                    throw new StudioPersistenceRace('The entry override record moved.');
                }
                $this->compositionOverrides = $next;
            },
        );
        $artifacts = $this->createStub(StudioArtifactRepository::class);
        $artifacts->method('current')->willReturnCallback(
            fn (string $site, string $id): ?StoredStudioArtifact => str_starts_with(
                $id,
                EntryCompositionOverrides::ITEM_BLUEPRINT_PREFIX,
            ) ? $this->compositionItemHead($id) : $this->compositionArtifact,
        );
        $artifacts->method('revision')->willReturnCallback(
            function (string $site, string $id, string $version, string $revision): ?StoredStudioArtifact {
                foreach ($this->compositionItemArtifacts as $artifact) {
                    if ($artifact->id === $id && $artifact->version === $version && $artifact->revision === $revision) {
                        return $artifact;
                    }
                }

                return null;
            },
        );
        $artifacts->method('store')->willReturnCallback(
            function (StoredStudioArtifact $artifact, ?string $expected): bool {
                if (!str_starts_with($artifact->id, EntryCompositionOverrides::ITEM_BLUEPRINT_PREFIX)) {
                    $this->compositionArtifact = $artifact;

                    return true;
                }
                if (
                    $this->compositionItemHeadMoved
                    || $this->compositionItemHead($artifact->id)?->revision !== $expected
                ) {
                    return false;
                }
                $this->compositionItemArtifacts[] = $artifact;

                return true;
            },
        );
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
        $theme = new StudioPublishedTheme(
            $settings,
            new ActiveExtensionSet(new ExtensionContributionRegistrySet(
                new DeterministicCanonicalEncoder(),
                new SdkFieldConfigurationAdmission(),
                withCore: false,
            )),
            new StudioBuiltInThemeRelease(str_repeat('a', 64)),
        );
        $admission = new StudioArtifactAdmission(StudioDocumentSchemaRegistry::fromVendoredCorpus());
        $blocks = new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer());

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
            $admission,
            $artifacts,
            $transactions,
            $audit,
            $clock,
            new StudioCompositionContributionCatalog($registries, $blocks),
            $theme,
            $overrideStore,
            new StudioPublishedCompositionGuard($admission, $models, $theme, $blocks, $registries, $bindings),
        );
    }

    /**
     * The latest stored revision of one item layout Blueprint, or null before its first.
     *
     * @param   string  $id  Item layout Blueprint identity.
     *
     * @return  ?StoredStudioArtifact  The item Blueprint head.
     *
     * @since   2.0.0
     */
    private function compositionItemHead(string $id): ?StoredStudioArtifact
    {
        $head = null;
        foreach ($this->compositionItemArtifacts as $artifact) {
            if ($artifact->id === $id) {
                $head = $artifact;
            }
        }

        return $head;
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
