<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Projection;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Content\Application\ContentModelRepository;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentRepository;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Content\Domain\ContentEntry;
use Kumwe\App\Content\Domain\ContentTypeDefinition;
use Kumwe\App\Content\Domain\JsonSchemaValidator;
use Kumwe\App\Content\Domain\PublicationWindow;
use Kumwe\App\Content\Domain\SchemaCompatibilityChecker;
use Kumwe\App\Extension\Contribution\CanonicalCompositionDocument;
use Kumwe\App\Extension\Contribution\CanonicalCompositionKind;
use Kumwe\App\Extension\Contribution\CapabilityDefinition;
use Kumwe\App\Extension\Contribution\CompositionHostBinding;
use Kumwe\App\Extension\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\ManifestContributionSet;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Extension\Runtime\ContributedStudioPreviewBlockRendererRegistry;
use Kumwe\App\Presentation\Application\SitePresentation;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Studio\Application\Composition\ContentBlueprintBindingStore;
use Kumwe\App\Studio\Application\Composition\StudioBuiltInThemeRelease;
use Kumwe\App\Studio\Application\Composition\StudioCompositionModelMismatch;
use Kumwe\App\Studio\Application\Composition\StudioCompositionThemeMismatch;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Studio\Application\Composition\StudioCompositionContributionCatalog;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioArtifactRepository;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Host\StudioModelHostPort;
use Kumwe\App\Studio\Application\Projection\ContentProjectionBindingRepository;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Application\Projection\RecordAuthorizedStudioContentFieldDisclosure;
use Kumwe\App\Studio\Application\Projection\StudioContentProjectionService;
use Kumwe\App\Studio\Application\Projection\StudioProjectionRejected;
use Kumwe\App\Studio\Application\Preview\ContentStudioPreviewBindingSource;
use Kumwe\App\Studio\Application\Preview\CoreStudioPreviewBlockRendererRegistry;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockRenderer;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use Kumwe\App\Studio\Domain\Contract\StudioContractSchemas;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Kumwe\App\Studio\Domain\Projection\EntryCompositionOverrides;
use Kumwe\App\Studio\Domain\Projection\StudioProjectionRejection;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\ImmediateTransactionManager;
use Kumwe\App\Workflow\Domain\Workflow;
use Kumwe\App\Workflow\Domain\WorkflowDefinition;
use Kumwe\App\Workflow\Domain\WorkflowStateDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Proves the Studio Content read boundary delegates only through authorized, version-pinned services.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioContentProjectionService::class)]
#[CoversClass(ContentStudioPreviewBindingSource::class)]
#[CoversClass(StudioModelHostPort::class)]
#[CoversClass(StudioContentCompositionService::class)]
#[CoversClass(StudioCompositionModelMismatch::class)]
#[UsesClass(ContentModelService::class)]
#[UsesClass(ContentService::class)]
#[UsesClass(ContentStudioProjector::class)]
#[UsesClass(RecordAuthorizedStudioContentFieldDisclosure::class)]
#[UsesClass(StudioProjectionRejected::class)]
#[UsesClass(ContentTypeDefinition::class)]
#[UsesClass(ContentEntry::class)]
#[UsesClass(ContentRecord::class)]
#[UsesClass(ContentBlueprintBinding::class)]
#[UsesClass(CanonicalCompositionDocument::class)]
#[UsesClass(CapabilityDefinition::class)]
#[UsesClass(CompositionHostBinding::class)]
#[UsesClass(ExtensionContributionRegistrySet::class)]
#[UsesClass(ManifestContributionSet::class)]
#[UsesClass(StudioPreviewRendererContribution::class)]
#[UsesClass(ContributedStudioPreviewBlockRendererRegistry::class)]
#[UsesClass(CoreStudioPreviewBlockRendererRegistry::class)]
#[UsesClass(StudioPreviewBlockFragment::class)]
#[UsesClass(EntryCompositionOverrides::class)]
#[UsesClass(JsonSchemaValidator::class)]
#[UsesClass(SchemaCompatibilityChecker::class)]
#[UsesClass(StudioContractSchemas::class)]
#[UsesClass(Workflow::class)]
#[UsesClass(WorkflowDefinition::class)]
#[UsesClass(WorkflowStateDefinition::class)]
final class StudioContentProjectionServiceTest extends TestCase
{
    /**
     * Stable definition coordinate used across every service delegation.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string TYPE_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026be710';

    /**
     * Stable entry coordinate used across every service delegation.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ENTRY_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026be720';

    /**
     * Stable custom workflow coordinate pinned by the definition and entry.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string WORKFLOW_ID = '018f22e2-7c8b-7ab0-8f3a-88e8026be730';

    /**
     * `vector.host-vector.model.list.authorized` preserves authorized deterministic coordinates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testModelsDelegateToTheAuthorizedCollectionAndExactBindingCoordinate(): void
    {
        $definition = $this->definition();
        $binding = $this->binding();
        $models = $this->createMock(ContentModelRepository::class);
        $models->expects(self::once())
            ->method('contentTypes')
            ->with(self::callback(self::isDefaultSite(...)))
            ->willReturn([$definition]);
        $content = $this->createStub(ContentRepository::class);
        $bindings = $this->createMock(ContentProjectionBindingRepository::class);
        $bindings->expects(self::once())
            ->method('blueprint')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 4)
            ->willReturn($binding);

        $port = new StudioModelHostPort($this->service($models, $content, $bindings));
        $request = new StudioHostRequest(
            'studio.operation/model.list',
            '0.1.0-draft.2',
            'requests/model-list-1',
            'contexts/vector',
            'session-r1',
            new \stdClass(),
            null,
            null,
            null,
            null,
        );
        $snapshot = (new \ReflectionClass(StudioHostSessionSnapshot::class))->newInstanceWithoutConstructor();
        $documents = $port->dispatch($this->allowedContext(), 'list', $request, $snapshot)->value;

        self::assertCount(1, $documents);
        self::assertSame('content-model:' . self::TYPE_ID, $documents[0]->id);
        self::assertSame(
            'kumwe.blueprints/article',
            $documents[0]->extensions->{'kumwe.app/blueprint-binding'}->id,
        );
    }

    /**
     * `vector.host-vector.model.get.stored` resolves its exact wrapped reference through AP-2.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testModelGetStoredVectorUsesTheAuthorizedExactProjection(): void
    {
        $models = $this->createMock(ContentModelRepository::class);
        $models->expects(self::once())
            ->method('contentType')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 4)
            ->willReturn($this->definition());
        $bindings = $this->createMock(ContentProjectionBindingRepository::class);
        $bindings->expects(self::once())->method('blueprint')->willReturn($this->binding());
        $port = new StudioModelHostPort($this->service(
            $models,
            $this->createStub(ContentRepository::class),
            $bindings,
        ));
        $request = new StudioHostRequest(
            'studio.operation/model.get',
            '0.1.0-draft.2',
            'requests/model-get-1',
            'contexts/vector',
            'session-r1',
            (object) ['reference' => (object) [
                'id' => 'content-model:' . self::TYPE_ID,
                'version' => '0.0.4',
                'revision' => 'content-type-v4',
            ]],
            null,
            null,
            null,
            null,
        );
        $snapshot = (new \ReflectionClass(StudioHostSessionSnapshot::class))->newInstanceWithoutConstructor();

        $result = $port->dispatch($this->allowedContext(), 'get', $request, $snapshot);

        self::assertSame('content-model:' . self::TYPE_ID, $result->value->id);
        self::assertSame('0.0.4', $result->value->version);
        self::assertSame($result->value->revision, $result->revision);
    }

    /**
     * Provisioning admits one empty draft and binding in one transaction, then reuses that head.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCompositionProvisioningIsSchemaValidTransactionalAndIdempotent(): void
    {
        $models = $this->createStub(ContentModelRepository::class);
        $models->method('contentType')->willReturn($this->definition());
        $currentBinding = null;
        $bindings = $this->createStub(ContentProjectionBindingRepository::class);
        $bindings->method('blueprint')->willReturnCallback(
            static function () use (&$currentBinding): ?ContentBlueprintBinding {
                return $currentBinding;
            },
        );
        $projection = $this->service($models, $this->createStub(ContentRepository::class), $bindings);
        $bindingStore = $this->createMock(ContentBlueprintBindingStore::class);
        $bindingStore->expects(self::once())->method('add')->willReturnCallback(
            static function (ContentBlueprintBinding $binding) use (&$currentBinding): void {
                $currentBinding = $binding;
            },
        );
        $stored = null;
        $artifacts = $this->createMock(StudioArtifactRepository::class);
        $artifacts->method('current')->willReturnCallback(static function () use (&$stored) {
            return $stored;
        });
        $artifacts->method('revision')->willReturn(null);
        $artifacts->expects(self::once())->method('store')->willReturnCallback(
            static function ($artifact, ?string $expected) use (&$stored): bool {
                self::assertNull($expected);
                $stored = $artifact;
                return true;
            },
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(self::now());
        $audit = $this->createMock(AuditRecorder::class);
        $audit->expects(self::once())->method('record');
        $catalog = $this->compositionCatalog();
        $settingsDocument = ['presentation' => SitePresentation::defaults()];
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturnCallback(
            static function () use (&$settingsDocument): array {
                return $settingsDocument;
            },
        );
        $theme = new StudioPublishedTheme(
            $settings,
            new ActiveExtensionSet(new ExtensionContributionRegistrySet(withCore: false)),
            new StudioBuiltInThemeRelease(str_repeat('a', 64)),
        );
        $admission = new StudioArtifactAdmission(StudioContractSchemas::fromVendoredCorpus());
        $service = new StudioContentCompositionService(
            $projection,
            $bindings,
            $bindingStore,
            $admission,
            $artifacts,
            new ImmediateTransactionManager(),
            $audit,
            $clock,
            $catalog,
            $theme,
        );

        $first = $service->provision(
            AuthorizationContext::human(
                ['content.read'],
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb303',
            ),
            self::TYPE_ID,
            4,
            ['acme.shop.renderer.grid', 'core.renderer/field', 'core.renderer/layout'],
        );
        $second = $service->provision(
            AuthorizationContext::human(
                ['content.read'],
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb304',
            ),
            self::TYPE_ID,
            4,
            ['acme.shop.renderer.grid', 'core.renderer/field', 'core.renderer/layout'],
        );

        self::assertSame($first->binding->blueprintId, $second->binding->blueprintId);
        self::assertSame(1, $first->binding->revision);
        self::assertNull($first->binding->blueprintRevision);
        self::assertSame('draft', $first->blueprint->status);
        self::assertSame([], $first->blueprint->document()->roots);
        self::assertCount(14, $first->blueprint->document()->dependencyLock->blocks);
        $lockedTypes = array_map(
            static fn (\stdClass $lock): string => $lock->type,
            $first->blueprint->document()->dependencyLock->blocks,
        );
        self::assertContains('acme.shop/grid', $lockedTypes);
        $restricted = $catalog->project(
            [],
            ['acme.shop.renderer.grid', 'core.renderer/field', 'core.renderer/layout'],
            $first->blueprint->document()->dependencyLock->blocks,
        );
        $privileged = $catalog->project(
            ['acme.shop.catalog.edit' => true],
            ['acme.shop.renderer.grid', 'core.renderer/field', 'core.renderer/layout'],
            $first->blueprint->document()->dependencyLock->blocks,
        );
        self::assertNotContains('acme.shop/grid', self::compositionBlockTypes($restricted->documents));
        self::assertContains('acme.shop/grid', self::compositionBlockTypes($privileged->documents));
        self::assertSame('acme.shop/preview-grid', $restricted->blockRenderers['acme.shop/grid']);
        self::assertSame('core.theme/site', $first->blueprint->document()->dependencyLock->theme->id);
        self::assertSame(
            $theme->reference(SiteContext::fromString('default'))->revision,
            $first->blueprint->document()->dependencyLock->theme->revision,
        );

        $modelDrift = [
            'id' => 'content-model:018f22e2-7c8b-7ab0-8f3a-88e8026be711',
            'version' => '0.0.5',
            'revision' => 'content-type-v5',
        ];
        $mismatches = 0;
        foreach ($modelDrift as $member => $value) {
            $document = $first->blueprint->document();
            $document->model->{$member} = $value;
            $stored = $admission->admit(SiteContext::DEFAULT, $document);
            try {
                $service->find(
                    AuthorizationContext::human(
                        ['content.read'],
                        '018f22e2-7c8b-7ab0-8f3a-88e8026bb310',
                    ),
                    self::TYPE_ID,
                    4,
                );
            } catch (StudioCompositionModelMismatch $failure) {
                self::assertSame(
                    'The Studio Blueprint Content-model lock requires an explicit migration.',
                    $failure->getMessage(),
                );
                $mismatches++;
            }
        }
        self::assertSame(count($modelDrift), $mismatches);
        $stored = $first->blueprint;

        $settingsDocument['presentation']['active_scheme'] = 'ocean';
        $this->expectException(StudioCompositionThemeMismatch::class);
        $service->find(
            AuthorizationContext::human(
                ['content.read'],
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb305',
            ),
            self::TYPE_ID,
            4,
        );
    }

    /**
     * Exact reversible model and entry coordinates drive pinned definition, workflow and metadata reads.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testModelAndEntryLoadExactPinnedDependenciesBeforeProjection(): void
    {
        $definition = $this->definition(self::WORKFLOW_ID, 3);
        $record = $this->record();
        $workflow = $this->workflow();
        $models = $this->createMock(ContentModelRepository::class);
        $models->expects(self::exactly(2))
            ->method('contentType')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 4)
            ->willReturn($definition);
        $models->expects(self::once())
            ->method('workflow')
            ->with(self::callback(self::isDefaultSite(...)), self::WORKFLOW_ID, 3)
            ->willReturn($workflow);
        $content = $this->createMock(ContentRepository::class);
        $content->expects(self::once())
            ->method('find')
            ->with(self::ENTRY_ID, false)
            ->willReturn($record);
        $bindings = $this->createMock(ContentProjectionBindingRepository::class);
        $bindings->expects(self::once())
            ->method('blueprint')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 4)
            ->willReturn($this->binding());
        $bindings->expects(self::once())
            ->method('overrides')
            ->with(self::callback(self::isDefaultSite(...)), self::ENTRY_ID)
            ->willReturn($this->overrides());
        $service = $this->service($models, $content, $bindings);
        $context = $this->allowedContext();

        $model = $service->model($context, 'content-model:' . self::TYPE_ID, '0.0.4');
        $entry = $service->entry($context, 'content-entry:' . self::ENTRY_ID);

        self::assertSame('0.0.4', $model->version);
        self::assertSame('kumwe.blueprints/article', $model->extensions->{'kumwe.app/blueprint-binding'}->id);
        self::assertSame('content-model:' . self::TYPE_ID, $entry->model->id);
        self::assertSame('legal_review', $entry->extensions->{'kumwe.app/content-entry'}->workflowState);
        self::assertSame('quiet', $entry->compositionOverrides->{'hero/main'}->tone);
    }

    /**
     * Invalid Studio coordinates stop before any Content or projection-metadata store is consulted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvalidCoordinatesAreRejectedBeforeAuthoritativeReads(): void
    {
        $models = $this->createMock(ContentModelRepository::class);
        $models->expects(self::never())->method(self::anything());
        $content = $this->createMock(ContentRepository::class);
        $content->expects(self::never())->method(self::anything());
        $bindings = $this->createMock(ContentProjectionBindingRepository::class);
        $bindings->expects(self::never())->method(self::anything());
        $service = $this->service($models, $content, $bindings);
        $context = $this->allowedContext();

        $this->assertRefusal(
            fn () => $service->model($context, 'blueprint:' . self::TYPE_ID),
            StudioProjectionRejection::InvalidIdentifier,
        );
        $this->assertRefusal(
            fn () => $service->model($context, 'content-model:' . self::TYPE_ID, '4'),
            StudioProjectionRejection::InvalidIdentifier,
        );
        $this->assertRefusal(
            fn () => $service->entry($context, 'entry:' . self::ENTRY_ID),
            StudioProjectionRejection::InvalidIdentifier,
        );
    }

    /**
     * Denial and absent model or entry rows collapse to the identical non-disclosing refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeniedAndMissingReadsCollapseToUnavailable(): void
    {
        $deniedModels = $this->createMock(ContentModelRepository::class);
        $deniedModels->expects(self::never())->method(self::anything());
        $deniedContent = $this->createMock(ContentRepository::class);
        $deniedContent->expects(self::never())->method(self::anything());
        $deniedBindings = $this->createMock(ContentProjectionBindingRepository::class);
        $deniedBindings->expects(self::never())->method(self::anything());
        $this->assertRefusal(
            fn () => $this->service($deniedModels, $deniedContent, $deniedBindings)
                ->models(AuthorizationContext::human([])),
            StudioProjectionRejection::Unavailable,
        );

        $missingModels = $this->createMock(ContentModelRepository::class);
        $missingModels->expects(self::once())
            ->method('contentType')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 4)
            ->willReturn(null);
        $missingModelBindings = $this->createMock(ContentProjectionBindingRepository::class);
        $missingModelBindings->expects(self::never())->method(self::anything());
        $this->assertRefusal(
            fn () => $this->service(
                $missingModels,
                $this->createStub(ContentRepository::class),
                $missingModelBindings,
            )->model($this->allowedContext(), 'content-model:' . self::TYPE_ID, '0.0.4'),
            StudioProjectionRejection::Unavailable,
        );

        $missingContent = $this->createMock(ContentRepository::class);
        $missingContent->expects(self::once())
            ->method('find')
            ->with(self::ENTRY_ID, false)
            ->willReturn(null);
        $missingEntryBindings = $this->createMock(ContentProjectionBindingRepository::class);
        $missingEntryBindings->expects(self::never())->method(self::anything());
        $this->assertRefusal(
            fn () => $this->service(
                $this->createStub(ContentModelRepository::class),
                $missingContent,
                $missingEntryBindings,
            )->entry($this->allowedContext(), 'content-entry:' . self::ENTRY_ID),
            StudioProjectionRejection::Unavailable,
        );
    }

    /**
     * An entry whose exact custom workflow version is absent is unavailable before overrides are queried.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingPinnedWorkflowCollapsesToUnavailableBeforeOverrideLookup(): void
    {
        $definition = $this->definition(self::WORKFLOW_ID, 3);
        $models = $this->createMock(ContentModelRepository::class);
        $models->expects(self::once())
            ->method('contentType')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 4)
            ->willReturn($definition);
        $models->expects(self::once())
            ->method('workflow')
            ->with(self::callback(self::isDefaultSite(...)), self::WORKFLOW_ID, 3)
            ->willReturn(null);
        $content = $this->createMock(ContentRepository::class);
        $content->expects(self::once())
            ->method('find')
            ->with(self::ENTRY_ID, false)
            ->willReturn($this->record());
        $bindings = $this->createMock(ContentProjectionBindingRepository::class);
        $bindings->expects(self::never())->method(self::anything());

        $this->assertRefusal(
            fn () => $this->service($models, $content, $bindings)
                ->entry($this->allowedContext(), 'content-entry:' . self::ENTRY_ID),
            StudioProjectionRejection::Unavailable,
        );
    }

    /**
     * Preview binding reuses the authorized entry projection and proves the exact Blueprint lock.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPreviewBindingReadsOnlyAuthorizedProjectedEntryValues(): void
    {
        $definition = $this->definition(self::WORKFLOW_ID, 3);
        $models = $this->createMock(ContentModelRepository::class);
        $models->expects(self::exactly(3))
            ->method('contentType')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 4)
            ->willReturn($definition);
        $models->expects(self::once())
            ->method('workflow')
            ->with(self::callback(self::isDefaultSite(...)), self::WORKFLOW_ID, 3)
            ->willReturn($this->workflow());
        $content = $this->createMock(ContentRepository::class);
        $content->expects(self::once())
            ->method('find')
            ->with(self::ENTRY_ID, false)
            ->willReturn($this->record());
        $bindings = $this->createMock(ContentProjectionBindingRepository::class);
        $bindings->expects(self::exactly(2))
            ->method('blueprint')
            ->with(self::callback(self::isDefaultSite(...)), self::TYPE_ID, 4)
            ->willReturn($this->binding());
        $bindings->expects(self::once())
            ->method('overrides')
            ->with(self::callback(self::isDefaultSite(...)), self::ENTRY_ID)
            ->willReturn($this->overrides());
        $context = $this->allowedContext();
        $service = $this->service($models, $content, $bindings);
        $model = $service->model($context, 'content-model:' . self::TYPE_ID, '0.0.4');
        $coordinate = $model->extensions->{'kumwe.app/blueprint-binding'};
        $document = (object) [
            'kind' => 'blueprint',
            'id' => $coordinate->id,
            'version' => $coordinate->version,
            'revision' => $coordinate->revision,
            'model' => (object) [
                'id' => $model->id,
                'version' => $model->version,
                'revision' => $model->revision,
            ],
            'roots' => [],
        ];
        $session = new StudioHostSession(
            'contexts/content-preview',
            AuthorizationContext::SUBJECT,
            SiteContext::DEFAULT,
            null,
            null,
            'administrator',
            hash('sha256', 'content-preview-session'),
            StudioSessionMode::Content,
            StudioResourceKind::Content,
            'content-entry:' . self::ENTRY_ID,
            'session-content-preview',
        );

        $values = (new ContentStudioPreviewBindingSource($service))->resolve(
            $context,
            new StudioHostSessionSnapshot(
                $session,
                ['studio.permission/read'],
                $session->sessionGeneration,
                true,
                false,
                false,
            ),
            new StudioPreviewDraft(SiteContext::DEFAULT, $document),
        );

        self::assertSame('Exact body.', $values->entry()->data_body);
        self::assertFalse(property_exists($values->entry(), 'compositionOverrides'));
        self::assertSame([], get_object_vars($values->context()));
    }

    /**
     * Compose the service from the real authorized Content facades and pure projector.
     *
     * @param   ContentModelRepository              $models    Content definition and workflow store double.
     * @param   ContentRepository                   $content   Content entry store double.
     * @param   ContentProjectionBindingRepository  $bindings  Projection metadata store double.
     *
     * @return  StudioContentProjectionService
     *
     * @since   2.0.0
     */
    private function service(
        ContentModelRepository $models,
        ContentRepository $content,
        ContentProjectionBindingRepository $bindings,
    ): StudioContentProjectionService {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(self::now());
        $authorization = AuthorizationContext::gateway();
        $transactions = new ImmediateTransactionManager();
        $audit = $this->createStub(AuditRecorder::class);

        return new StudioContentProjectionService(
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
                $content,
                $audit,
                $transactions,
                $clock,
                new Workflow(),
                $authorization,
                AuthorizationContext::ownershipWriter(),
            ),
            $bindings,
            new ContentStudioProjector(
                StudioContractSchemas::fromVendoredCorpus(),
                new RecordAuthorizedStudioContentFieldDisclosure(),
                new JsonSchemaValidator(),
            ),
        );
    }

    /**
     * Build the version-four Content definition projected by the service.
     *
     * @param   string  $workflowId       Workflow coordinate pinned by this version.
     * @param   int     $workflowVersion  Exact workflow version pinned by this version.
     *
     * @return  ContentTypeDefinition
     *
     * @since   2.0.0
     */
    private function definition(
        string $workflowId = ContentService::CORE_WORKFLOW_ID,
        int $workflowVersion = 1,
    ): ContentTypeDefinition {
        return new ContentTypeDefinition(
            self::TYPE_ID,
            SiteContext::default(),
            'article',
            'Article',
            $workflowId,
            $workflowVersion,
            [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['body' => ['type' => 'string']],
                'required' => ['body'],
            ],
            4,
            self::now(),
            self::now(),
        );
    }

    /**
     * Build the custom-workflow record returned by the authoritative Content service.
     *
     * @return  ContentRecord
     *
     * @since   2.0.0
     */
    private function record(): ContentRecord
    {
        return new ContentRecord(
            ContentEntry::reconstitute(
                self::ENTRY_ID,
                'Exact title',
                'exact-entry',
                ['body' => 'Exact body.'],
                'legal_review',
                PublicationWindow::unbounded(),
                5,
            ),
            self::TYPE_ID,
            self::WORKFLOW_ID,
            self::now(),
            self::now(),
            contentTypeVersion: 4,
            workflowVersion: 3,
        );
    }

    /**
     * Build the exact custom workflow version pinned by the record.
     *
     * @return  WorkflowDefinition
     *
     * @since   2.0.0
     */
    private function workflow(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            self::WORKFLOW_ID,
            SiteContext::default(),
            'editorial',
            'Editorial',
            [
                new WorkflowStateDefinition('writing', 'Writing', true, false),
                new WorkflowStateDefinition('legal_review', 'Legal review', false, false),
                new WorkflowStateDefinition('live', 'Live', false, true),
            ],
            [],
            3,
            self::now(),
            self::now(),
        );
    }

    /**
     * Build the exact-version Blueprint coordinate returned for a model.
     *
     * @return  ContentBlueprintBinding
     *
     * @since   2.0.0
     */
    private function binding(): ContentBlueprintBinding
    {
        return new ContentBlueprintBinding(
            SiteContext::default(),
            self::TYPE_ID,
            4,
            'kumwe.blueprints/article',
            '1.5.0',
            'artifact-22',
            2,
        );
    }

    /**
     * Build the per-entry composition overrides returned after every authoritative read succeeds.
     *
     * @return  EntryCompositionOverrides
     *
     * @since   2.0.0
     */
    private function overrides(): EntryCompositionOverrides
    {
        return new EntryCompositionOverrides(
            SiteContext::default(),
            self::ENTRY_ID,
            (object) ['hero/main' => (object) ['tone' => 'quiet']],
            6,
        );
    }

    /**
     * Return an authorized Content reader at the default site.
     *
     * @return  ExecutionContext
     *
     * @since   2.0.0
     */
    private function allowedContext(): ExecutionContext
    {
        return AuthorizationContext::human(['content.read']);
    }

    /**
     * Build one capability-gated, renderer-supported extension contribution for provisioning tests.
     *
     * @return  StudioCompositionContributionCatalog
     *
     * @since   2.0.0
     */
    private function compositionCatalog(): StudioCompositionContributionCatalog
    {
        $owner = ContributionOwner::extension('acme/shop');
        $document = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 4) . '/Fixtures/Studio/testkit/fixtures/block.grid.example.json',
            ),
            false,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(\stdClass::class, $document);
        $document->type = 'acme.shop/grid';
        $document->owner = (object) ['id' => 'acme.shop/blocks', 'version' => '1.0.0'];
        $document->rendererRequirements = [
            (object) [
                'surface' => 'web',
                'capability' => 'acme.shop/web-grid',
                'versions' => '^1.0.0',
            ],
            (object) [
                'surface' => 'preview',
                'capability' => 'acme.shop/preview-grid',
                'versions' => '^1.0.0',
            ],
        ];
        $canonical = new CanonicalCompositionDocument(
            CanonicalCompositionKind::BlockDefinition,
            CanonicalJson::stringify($document),
        );
        $capability = new CapabilityDefinition(
            'acme.shop.catalog.edit',
            'Edit shop catalog',
            'Offer the shop catalog composition blocks to an author.',
        );
        $binding = new CompositionHostBinding(
            CanonicalCompositionKind::BlockDefinition,
            'acme.shop/grid',
            'acme.shop.renderer.grid',
            $capability->id,
        );
        $declared = new ManifestContributionSet(
            $owner,
            spiVersion: ManifestContributionSet::CANONICAL_COMPOSITION_SPI_VERSION,
            capabilities: [$capability],
            canonicalDocuments: [$canonical],
            compositionHostBindings: [$binding],
        );
        $registries = new ExtensionContributionRegistrySet();
        $registrar = $registries->registrar($owner, $declared);
        $registrar->capability($capability);
        $registrar->canonicalCompositionDocument($canonical);
        $registrar->complete();
        $registries->studioPreviewRenderers()->register(
            $owner,
            new StudioPreviewRendererContribution($owner, '1.0.0', $canonical, $binding),
            new class implements StudioPreviewBlockRenderer {
                /**
                 * Return one inert safe fragment for the exact contributed block.
                 *
                 * @param   StudioPreviewBlock          $block     Admitted block input.
                 * @param   StudioPreviewBindingResult  $binding   Authorized binding result.
                 * @param   string                      $viewport  Active semantic viewport.
                 *
                 * @return  StudioPreviewBlockFragment  Closed safe fragment.
                 *
                 * @since   2.0.0
                 */
                public function render(
                    StudioPreviewBlock $block,
                    StudioPreviewBindingResult $binding,
                    string $viewport,
                ): StudioPreviewBlockFragment {
                    return new StudioPreviewBlockFragment('div', 'acme-shop-grid', '');
                }
            },
        );

        return new StudioCompositionContributionCatalog(
            $registries,
            new ContributedStudioPreviewBlockRendererRegistry(
                new CoreStudioPreviewBlockRendererRegistry(),
                $registries->studioPreviewRenderers(),
            ),
        );
    }

    /**
     * Return the exact block types from projected canonical contribution documents.
     *
     * @param   list<\stdClass>  $documents  Projected canonical documents.
     *
     * @return  list<string>  Exact projected block types.
     *
     * @since   2.0.0
     */
    private static function compositionBlockTypes(array $documents): array
    {
        return array_values(array_map(
            static fn (\stdClass $document): string => $document->type,
            array_filter(
                $documents,
                static fn (\stdClass $document): bool => ($document->kind ?? null) === 'block-definition',
            ),
        ));
    }

    /**
     * Recognize the exact tenant scope expected by every repository call.
     *
     * @param   SiteContext  $site  Site received by a repository double.
     *
     * @return  bool
     *
     * @since   2.0.0
     */
    private static function isDefaultSite(SiteContext $site): bool
    {
        return $site->identifier() === SiteContext::DEFAULT;
    }

    /**
     * Assert one service read stops with a non-disclosing typed refusal.
     *
     * @param   callable(): mixed          $operation  Read expected to stop.
     * @param   StudioProjectionRejection  $reason     Stable refusal category.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertRefusal(callable $operation, StudioProjectionRejection $reason): void
    {
        try {
            $operation();
            self::fail('The Studio Content service unexpectedly disclosed a document.');
        } catch (StudioProjectionRejected $failure) {
            self::assertSame($reason, $failure->rejection);
            self::assertSame('', $failure->path);
            self::assertSame('The requested content projection is unavailable.', $failure->getMessage());
            $diagnostic = json_encode($failure->diagnostic(), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::TYPE_ID, $diagnostic);
            self::assertStringNotContainsString(self::ENTRY_ID, $diagnostic);
        }
    }

    /**
     * Return the deterministic timestamp shared by every test fixture.
     *
     * @return  DateTimeImmutable
     *
     * @since   2.0.0
     */
    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-24T12:00:00+00:00');
    }
}
