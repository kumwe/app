<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Preview;

use DateTimeImmutable;
use Kumwe\Audit\Application\AuditRecorder;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Content\Application\ContentModelRepository;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\Content\Application\ContentRecord;
use Kumwe\Content\Application\ContentRepository;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\Content\Domain\ContentEntry;
use Kumwe\Content\Domain\ContentStatus;
use Kumwe\Content\Domain\ContentTypeDefinition;
use Kumwe\Content\Domain\JsonSchemaValidator;
use Kumwe\Content\Domain\SchemaCompatibilityChecker;
use Kumwe\App\BusinessSurface\Presentation\Field\SdkFieldConfigurationAdmission;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringCatalog;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextAuthority;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextBinding;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextRepository;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTarget;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTargetResolver;
use Kumwe\App\Studio\Application\Composition\StudioCompositionContributionCatalog;
use Kumwe\App\Studio\Application\Composition\StudioContentDefaultComposition;
use Kumwe\App\Studio\Application\Composition\StudioItemCompositionPolicy;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Application\Preview\ContentStudioPreviewBindingSource;
use Kumwe\App\Studio\Application\Preview\StudioPreviewRefused;
use Kumwe\App\Studio\Application\Projection\ContentProjectionBindingRepository;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Application\Projection\RecordAuthorizedStudioContentFieldDisclosure;
use Kumwe\App\Studio\Application\Projection\StudioContentProjectionService;
use Kumwe\App\Studio\Application\Release\StudioCoreCatalog;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioContentFieldBlockRenderer;
use Kumwe\Producer\Schema\StudioContractResources;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Kumwe\App\Studio\Domain\Projection\EntryCompositionOverrides;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;
use Kumwe\App\Tests\Support\InterfaceTranslation;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;
use Kumwe\Content\Workflow\Domain\Workflow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use stdClass;

/**
 * Exercises fail-closed resource and model-binding checks around authorized Content preview values.
 *
 * @since  2.0.0
 */
#[CoversClass(ContentStudioPreviewBindingSource::class)]
#[UsesClass(StudioContentProjectionService::class)]
#[UsesClass(StudioPreviewDraft::class)]
#[UsesClass(StudioContentDefaultComposition::class)]
#[UsesClass(ContentStudioAuthoringCatalog::class)]
#[UsesClass(EntryCompositionOverrides::class)]
#[UsesClass(ContentBlueprintBinding::class)]
#[UsesClass(StudioItemCompositionPolicy::class)]
final class ContentStudioPreviewBindingSourceTest extends TestCase
{
    /**
     * Content type the presentation scenario authors from.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string TYPE_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026be720';

    /**
     * Entry whose own item layout the item-layout scenario previews.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ENTRY_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026be721';

    /**
     * Refuse Blueprint sessions that name any artifact other than the exact retained draft.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testBlueprintSessionCannotResolveAnotherArtifact(): void
    {
        $source = new ContentStudioPreviewBindingSource($this->content(), $this->contexts(), self::catalog());
        $draft = self::draft();

        self::assertRefused(
            'studio.preview/resource-refused',
            fn () => $source->resolve(
                self::context(),
                self::snapshot(StudioResourceKind::Blueprint, 'blueprints/other'),
                $draft,
            ),
        );
    }

    /**
     * Collapse invalid Content coordinates and malformed draft model locks to preview-safe diagnostics.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testContentModelCoordinatesAndVersionsFailClosedBeforeProjection(): void
    {
        $source = new ContentStudioPreviewBindingSource($this->content(), $this->contexts(), self::catalog());

        self::assertRefused(
            'studio.preview/resource-refused',
            fn () => $source->resolve(
                self::context(),
                self::snapshot(StudioResourceKind::Content, 'invalid-model-coordinate'),
                self::draft((object) [
                    'id' => 'invalid-model-coordinate',
                    'revision' => 'r1',
                    'version' => '0.0.1',
                ]),
            ),
        );
        self::assertRefused(
            'studio.preview/resource-refused',
            fn () => $source->resolve(
                self::context(),
                self::snapshot(
                    StudioResourceKind::Content,
                    'content-model:018f22e2-7c8b-7ab0-8f3a-88e8026be710',
                ),
                self::draft((object) [
                    'id' => 'content-model:018f22e2-7c8b-7ab0-8f3a-88e8026be711',
                    'revision' => 'r1',
                    'version' => '0.0.1',
                ]),
            ),
        );
        self::assertRefused(
            'studio.preview/model-binding-mismatch',
            fn () => $source->resolve(
                self::context(),
                self::snapshot(
                    StudioResourceKind::Content,
                    'content-model:018f22e2-7c8b-7ab0-8f3a-88e8026be710',
                ),
                self::draft((object) [
                    'id' => 'content-model:018f22e2-7c8b-7ab0-8f3a-88e8026be710',
                    'revision' => 'r1',
                    'version' => 1,
                ]),
            ),
        );
    }

    /**
     * A contextual authoring session previews only a target its context authority re-authorizes, and a blank
     * canvas whose reusable type does not exist yet has nothing a preview could render.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testContextualAuthoringSessionsFollowTheirAuthorityAndRefuseABlankCanvas(): void
    {
        $administrator = AuthorizationContext::principal(['content.create', 'content.read'])->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'test-request-0002',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-session-preview',
        );
        $key = 'contexts/' . hash('sha256', 'blank-canvas-preview');
        $blank = new ContentStudioAuthoringContextBinding(
            $key,
            AuthorizationContext::SUBJECT,
            'default',
            null,
            null,
            AuthenticatedSurface::Administrator->value,
            hash('sha256', 'administrator-session-preview'),
            $administrator->approvalFingerprint(),
            new ContentStudioAuthoringTarget(
                StudioAuthoringIntent::Create,
                null,
                null,
                null,
                null,
                null,
                '/administrator/content/new',
            ),
            new DateTimeImmutable('2026-09-24T10:00:00+00:00'),
            new DateTimeImmutable('2026-09-24T11:00:00+00:00'),
        );
        $source = new ContentStudioPreviewBindingSource(
            $this->content(),
            $this->contexts([$key => $blank]),
            self::catalog(),
        );

        self::assertRefused(
            'studio.preview/resource-refused',
            fn () => $source->resolve(
                self::context(),
                self::snapshot(StudioResourceKind::ContentAuthoring, 'contexts/' . hash('sha256', 'unknown')),
                self::draft(),
            ),
            'a context nobody holds',
        );
        self::assertRefused(
            'studio.preview/resource-refused',
            fn () => $source->resolve(
                $administrator,
                self::snapshot(StudioResourceKind::ContentAuthoring, $key),
                self::draft(),
            ),
            'a blank canvas before its type exists',
        );
    }

    /**
     * A contextual authoring session is presented the derived default of a stored empty draft, and nothing else
     * is presented.
     *
     * The draft's identity, version, revision, model and lock are kept; only its roots become the default the
     * session was handed (App ADR 0024). A Blueprint session, a refused context, a model lock the target does
     * not project, and a draft that already composes roots all get the stored draft back unchanged.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testAContextualSessionIsPresentedTheDerivedDefaultOfAnEmptyDraft(): void
    {
        $administrator = AuthorizationContext::principal(['content.create', 'content.read'])->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'test-request-0003',
            surface: AuthenticatedSurface::Administrator,
            sessionId: 'administrator-session-presented',
        );
        $now = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
        $definition = new ContentTypeDefinition(
            self::TYPE_ID,
            SiteContext::default(),
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
        );
        $models = $this->createStub(ContentModelRepository::class);
        $models->method('contentType')->willReturn($definition);
        $key = 'contexts/' . hash('sha256', 'from-type-preview');
        $fromType = new ContentStudioAuthoringContextBinding(
            $key,
            AuthorizationContext::SUBJECT,
            'default',
            null,
            null,
            AuthenticatedSurface::Administrator->value,
            hash('sha256', 'administrator-session-presented'),
            $administrator->approvalFingerprint(),
            new ContentStudioAuthoringTarget(
                StudioAuthoringIntent::Create,
                ContentStudioProjector::modelId(self::TYPE_ID),
                ContentStudioProjector::modelVersion(4),
                ContentStudioProjector::modelRevision(4),
                null,
                null,
                '/administrator/content/new?content_type=' . rawurlencode(self::TYPE_ID),
            ),
            $now,
            new DateTimeImmutable('2026-09-24T11:00:00+00:00'),
        );
        $content = $this->content($models);
        $catalog = self::catalog();
        $source = new ContentStudioPreviewBindingSource(
            $content,
            $this->contexts([$key => $fromType], $models),
            $catalog,
        );
        $model = $content->model(
            $administrator,
            ContentStudioProjector::modelId(self::TYPE_ID),
            ContentStudioProjector::modelVersion(4),
        );
        $lock = (object) ['id' => $model->id, 'version' => $model->version, 'revision' => $model->revision];
        $stored = self::lockedDraft($lock, []);
        $contextual = self::snapshot(StudioResourceKind::ContentAuthoring, $key);

        $presented = $source->present($administrator, $contextual, $stored);

        self::assertNotSame($stored, $presented);
        self::assertSame($stored->siteIdentifier, $presented->siteIdentifier);
        self::assertSame($stored->artifactId(), $presented->artifactId());
        self::assertSame($stored->revision(), $presented->revision());
        self::assertNotSame($stored->digest(), $presented->digest());
        $document = $presented->document();
        $original = $stored->document();
        foreach (['version', 'status', 'model', 'dependencyLock'] as $member) {
            self::assertEquals($original->{$member}, $document->{$member}, $member . ' is kept.');
        }
        self::assertEquals(
            StudioContentDefaultComposition::roots($model, $original->dependencyLock->blocks),
            $document->roots,
        );
        self::assertCount(1, $document->roots);
        self::assertSame(StudioContentDefaultComposition::SECTION_ID, $document->roots[0]->id);
        self::assertSame(
            ['default/field/title', 'default/field/data:body'],
            array_column($document->roots[0]->slots->content, 'id'),
        );

        self::assertSame(
            $stored,
            $source->present(self::context(), self::snapshot(StudioResourceKind::Blueprint, 'blueprints/one'), $stored),
            'a Blueprint session is handed the stored document',
        );
        self::assertSame(
            $stored,
            $source->present(
                $administrator,
                self::snapshot(StudioResourceKind::ContentAuthoring, 'contexts/' . hash('sha256', 'unknown')),
                $stored,
            ),
            'a context nobody holds',
        );
        $foreign = self::lockedDraft(
            (object) ['id' => $model->id, 'version' => $model->version, 'revision' => 'another-model-revision'],
            [],
        );
        self::assertSame(
            $foreign,
            $source->present($administrator, $contextual, $foreign),
            'a model lock the target does not project',
        );
        $composed = self::lockedDraft($lock, $document->roots);
        self::assertSame(
            $composed,
            $source->present($administrator, $contextual, $composed),
            'a draft that already composes roots',
        );

        // A type save stores an empty layout with an empty lock; it is presented under the renderable locks.
        $unlockedDocument = self::lockedDraft($lock, [])->document();
        $unlockedDocument->dependencyLock->blocks = [];
        $unlocked = new StudioPreviewDraft('default', $unlockedDocument);
        $widened = $source->present($administrator, $contextual, $unlocked)->document();
        $renderable = $catalog->renderableBlockLocks();
        self::assertEquals(StudioContentDefaultComposition::roots($model, $renderable), $widened->roots);
        self::assertSame(
            ['default/field/title', 'default/field/data:body'],
            array_column($widened->roots[0]->slots->content, 'id'),
        );
        self::assertEquals(
            array_values(array_filter(
                $renderable,
                static fn (stdClass $lock): bool => in_array(
                    $lock->type,
                    ['studio.core/section', 'core/field-text'],
                    true,
                ),
            )),
            $widened->dependencyLock->blocks,
            'Exactly the renderable locks of the composed types, in deployment order.',
        );
        self::assertSame([], $unlocked->document()->dependencyLock->blocks, 'The stored document is unchanged.');
    }

    /**
     * An entry session previews the exact item layout its entry pins (App ADR 0025), keeps previewing its type's
     * bound layout, and refuses another entry's item layout and a layout locked to another type version. Under
     * the `denied` item-composition policy, the rollback, its own pinned layout is refused as well while its
     * type's bound layout still previews.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testAnEntrySessionPreviewsOnlyItsOwnPinnedItemLayout(): void
    {
        $now = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
        $definition = new ContentTypeDefinition(
            self::TYPE_ID,
            SiteContext::default(),
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
        );
        $models = $this->createStub(ContentModelRepository::class);
        $models->method('contentType')->willReturn($definition);
        $entries = $this->createStub(ContentRepository::class);
        $entries->method('find')->willReturn(new ContentRecord(
            ContentEntry::create(
                self::ENTRY_ID,
                'Item title',
                'item-title',
                ['body' => 'Item body.'],
                ContentStatus::Draft,
            ),
            self::TYPE_ID,
            ContentService::CORE_WORKFLOW_ID,
            $now,
            $now,
            contentTypeVersion: 4,
        ));
        $typeBlueprint = 'content-blueprint:' . self::TYPE_ID . ':v4';
        $pinned = 'item-' . hash('sha256', 'item layout');
        $bindings = $this->createStub(ContentProjectionBindingRepository::class);
        $bindings->method('blueprint')->willReturn(new ContentBlueprintBinding(
            SiteContext::default(),
            self::TYPE_ID,
            4,
            $typeBlueprint,
            '1.0.0',
            null,
            1,
        ));
        $bindings->method('overrides')->willReturn(
            new EntryCompositionOverrides(SiteContext::default(), self::ENTRY_ID, new stdClass(), 2, $pinned),
        );
        $source = new ContentStudioPreviewBindingSource(
            $this->content($models, $entries, $bindings),
            $this->contexts(),
            self::catalog(),
        );
        $session = self::snapshot(StudioResourceKind::Content, 'content-entry:' . self::ENTRY_ID);
        $model = (object) [
            'id' => ContentStudioProjector::modelId(self::TYPE_ID),
            'version' => ContentStudioProjector::modelVersion(4),
            'revision' => ContentStudioProjector::modelRevision(4),
        ];
        $itemId = EntryCompositionOverrides::ITEM_BLUEPRINT_PREFIX . self::ENTRY_ID;

        $own = $source->resolve(self::context(), $session, self::layout($itemId, $pinned, $model));
        self::assertSame('Item title', $own->entry()->title);
        self::assertSame('Item body.', $own->entry()->data_body);
        $type = $source->resolve(self::context(), $session, self::layout($typeBlueprint, 'type-r1', $model));
        self::assertSame('Item title', $type->entry()->title);

        $earlier = (object) [
            'id' => $model->id,
            'version' => ContentStudioProjector::modelVersion(3),
            'revision' => ContentStudioProjector::modelRevision(3),
        ];
        $refusals = [
            'another entry layout' => self::layout(
                EntryCompositionOverrides::ITEM_BLUEPRINT_PREFIX . '018f22e2-7c8b-7ab0-8f3a-88e8026be722',
                $pinned,
                $model,
            ),
            'another revision' => self::layout($itemId, 'item-' . hash('sha256', 'another layout'), $model),
            'another type version' => self::layout($itemId, $pinned, $earlier),
        ];
        foreach ($refusals as $label => $draft) {
            self::assertRefused(
                'studio.preview/model-binding-mismatch',
                fn () => $source->resolve(self::context(), $session, $draft),
                $label,
            );
        }

        $denied = new ContentStudioPreviewBindingSource(
            $this->content($models, $entries, $bindings),
            $this->contexts(),
            self::catalog(),
            new StudioItemCompositionPolicy('denied'),
        );
        self::assertSame(
            'Item title',
            $denied->resolve(self::context(), $session, self::layout($typeBlueprint, 'type-r1', $model))
                ->entry()->title,
        );
        self::assertRefused(
            'studio.preview/model-binding-mismatch',
            fn () => $denied->resolve(self::context(), $session, self::layout($itemId, $pinned, $model)),
            'own layout under the denied policy',
        );
    }

    /**
     * Build one stored preview draft at an exact Blueprint coordinate and model lock.
     *
     * @param   string    $id        Blueprint identity.
     * @param   string    $revision  Blueprint revision.
     * @param   stdClass  $model     Model lock.
     *
     * @return  StudioPreviewDraft  Immutable draft.
     *
     * @since  2.0.0
     */
    private static function layout(string $id, string $revision, stdClass $model): StudioPreviewDraft
    {
        return new StudioPreviewDraft('default', (object) [
            'kind' => 'blueprint',
            'id' => $id,
            'version' => '1.0.0',
            'revision' => $revision,
            'model' => clone $model,
            'roots' => [],
        ]);
    }

    /**
     * Build the real authoring catalog of the core blocks this deployment renders.
     *
     * @return  ContentStudioAuthoringCatalog  Catalog over the built-in contribution registries.
     *
     * @since  2.0.0
     */
    private static function catalog(): ContentStudioAuthoringCatalog
    {
        $registries = new ExtensionContributionRegistrySet(
            new DeterministicCanonicalEncoder(),
            new SdkFieldConfigurationAdmission(),
        );
        $runtime = new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer());

        return new ContentStudioAuthoringCatalog(
            new StudioCompositionContributionCatalog($registries, $runtime),
            StudioCoreCatalog::fromFile(
                dirname(__DIR__, 5) . '/resources/studio-contract/core-catalog.json',
                StudioContractResources::releaseRecord()->release(),
            ),
            $runtime,
            InterfaceTranslation::translator(),
        );
    }

    /**
     * Build the real projection boundary over inert persistence collaborators.
     *
     * @param   ContentModelRepository|null              $models    Optional Content type store; an inert one by
     *          default.
     * @param   ContentRepository|null                   $entries   Optional Content entry store; an inert one by
     *          default.
     * @param   ContentProjectionBindingRepository|null  $bindings  Optional projection metadata; an inert one by
     *          default.
     *
     * @return  StudioContentProjectionService  Normally constructed projection boundary.
     *
     * @since  2.0.0
     */
    private function content(
        ?ContentModelRepository $models = null,
        ?ContentRepository $entries = null,
        ?ContentProjectionBindingRepository $bindings = null,
    ): StudioContentProjectionService {
        return new StudioContentProjectionService(
            new ContentModelService(
                $models ?? $this->createStub(ContentModelRepository::class),
                new JsonSchemaValidator(),
                new SchemaCompatibilityChecker(),
                AuthorizationContext::gateway(),
                AuthorizationContext::ownershipWriter(),
                $this->createStub(AuditRecorder::class),
                new ImmediateTransactionManager(),
                $this->createStub(ClockInterface::class),
            ),
            new ContentService(
                $entries ?? $this->createStub(ContentRepository::class),
                $this->createStub(AuditRecorder::class),
                new ImmediateTransactionManager(),
                $this->createStub(ClockInterface::class),
                new Workflow(),
                AuthorizationContext::gateway(),
                AuthorizationContext::ownershipWriter(),
            ),
            $bindings ?? $this->createStub(ContentProjectionBindingRepository::class),
            new ContentStudioProjector(
                StudioDocumentSchemaRegistry::fromVendoredCorpus(),
                new RecordAuthorizedStudioContentFieldDisclosure(),
                new JsonSchemaValidator(),
            ),
        );
    }

    /**
     * Build the real context authority over an in-memory binding store.
     *
     * @param   array<string, ContentStudioAuthoringContextBinding>  $bindings  Bindings the store holds.
     * @param   ContentModelRepository|null                          $models    Optional Content type store; an
     *          inert one by default.
     *
     * @return  ContentStudioAuthoringContextAuthority  Normally constructed authority.
     *
     * @since  2.0.0
     */
    private function contexts(
        array $bindings = [],
        ?ContentModelRepository $models = null,
    ): ContentStudioAuthoringContextAuthority {
        $repository = $this->createStub(ContentStudioAuthoringContextRepository::class);
        $repository->method('find')->willReturnCallback(
            static fn (string $contextKey): ?ContentStudioAuthoringContextBinding => $bindings[$contextKey] ?? null,
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-24T10:30:00+00:00'));

        return new ContentStudioAuthoringContextAuthority(
            $repository,
            $this->createStub(StudioResourceContextKeyFactory::class),
            new ContentStudioAuthoringTargetResolver(AuthorizationContext::gateway()),
            new ContentModelService(
                $models ?? $this->createStub(ContentModelRepository::class),
                new JsonSchemaValidator(),
                new SchemaCompatibilityChecker(),
                AuthorizationContext::gateway(),
                AuthorizationContext::ownershipWriter(),
                $this->createStub(AuditRecorder::class),
                new ImmediateTransactionManager(),
                $this->createStub(ClockInterface::class),
            ),
            new ContentService(
                $this->createStub(ContentRepository::class),
                $this->createStub(AuditRecorder::class),
                new ImmediateTransactionManager(),
                $this->createStub(ClockInterface::class),
                new Workflow(),
                AuthorizationContext::gateway(),
                AuthorizationContext::ownershipWriter(),
            ),
            $clock,
            3600,
        );
    }

    /**
     * Build a valid preview draft around an optional Content model coordinate.
     *
     * @param   stdClass|null  $model  Optional draft model lock.
     *
     * @return  StudioPreviewDraft  Immutable draft.
     *
     * @since  2.0.0
     */
    private static function draft(?stdClass $model = null): StudioPreviewDraft
    {
        $document = (object) [
            'id' => 'blueprints/one',
            'kind' => 'blueprint',
            'revision' => 'r1',
            'roots' => [],
            'version' => '1.0.0',
        ];
        if ($model !== null) {
            $document->model = $model;
        }

        return new StudioPreviewDraft('default', $document);
    }

    /**
     * Build a stored draft Blueprint that locks one model and the section and text blocks a default composes.
     *
     * @param   stdClass        $model  Draft model lock.
     * @param   list<stdClass>  $roots  Stored roots.
     *
     * @return  StudioPreviewDraft  Immutable draft.
     *
     * @since  2.0.0
     */
    private static function lockedDraft(stdClass $model, array $roots): StudioPreviewDraft
    {
        return new StudioPreviewDraft('default', (object) [
            'kind' => 'blueprint',
            'id' => 'blueprints/one',
            'version' => '1.0.0',
            'revision' => 'r1',
            'status' => 'draft',
            'model' => $model,
            'dependencyLock' => (object) ['blocks' => [
                (object) ['type' => 'studio.core/section', 'version' => '1.0.0', 'revision' => 'layout-section-r1'],
                (object) ['type' => 'core/field-text', 'version' => '1.0.0', 'revision' => 'core-block-r2'],
            ]],
            'roots' => $roots,
        ]);
    }

    /**
     * Build one trusted preview session over a caller-supplied resource coordinate.
     *
     * @param   StudioResourceKind  $kind        Resource family.
     * @param   string              $resourceId  Resource coordinate.
     *
     * @return  StudioHostSessionSnapshot  Live session snapshot.
     *
     * @since  2.0.0
     */
    private static function snapshot(StudioResourceKind $kind, string $resourceId): StudioHostSessionSnapshot
    {
        $session = new StudioHostSession(
            'contexts/content-binding',
            AuthorizationContext::SUBJECT,
            'default',
            null,
            null,
            'administrator',
            hash('sha256', 'content-binding-session'),
            $kind === StudioResourceKind::Blueprint ? StudioSessionMode::Blueprint : StudioSessionMode::Content,
            $kind,
            $resourceId,
            'generation-content-binding',
        );

        return new StudioHostSessionSnapshot(
            $session,
            ['studio.permission/read'],
            $session->sessionGeneration,
            true,
            false,
            false,
        );
    }

    /**
     * Return one trusted Content-read context.
     *
     * @return  ExecutionContext  Authorized context.
     *
     * @since  2.0.0
     */
    private static function context(): ExecutionContext
    {
        return AuthorizationContext::human(['content.read']);
    }

    /**
     * Assert one binding operation fails with the expected preview diagnostic.
     *
     * @param   string    $code      Expected stable diagnostic.
     * @param   callable  $callback  Binding operation expected to fail.
     * @param   string    $case      Optional scenario label.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    private static function assertRefused(string $code, callable $callback, string $case = ''): void
    {
        try {
            $callback();
            self::fail('The invalid Studio preview binding was accepted: ' . $case);
        } catch (StudioPreviewRefused $refused) {
            self::assertSame($code, $refused->diagnosticCode, $case);
        }
    }
}
