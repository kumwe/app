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
use Kumwe\Content\Application\ContentRepository;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\Content\Domain\JsonSchemaValidator;
use Kumwe\Content\Domain\SchemaCompatibilityChecker;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextAuthority;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextBinding;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextRepository;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTarget;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTargetResolver;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Host\StudioResourceContextKeyFactory;
use Kumwe\App\Studio\Application\Preview\ContentStudioPreviewBindingSource;
use Kumwe\App\Studio\Application\Preview\StudioPreviewRefused;
use Kumwe\App\Studio\Application\Projection\ContentProjectionBindingRepository;
use Kumwe\App\Studio\Application\Projection\ContentStudioProjector;
use Kumwe\App\Studio\Application\Projection\RecordAuthorizedStudioContentFieldDisclosure;
use Kumwe\App\Studio\Application\Projection\StudioContentProjectionService;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\App\Tests\Support\AuthorizationContext;
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
final class ContentStudioPreviewBindingSourceTest extends TestCase
{
    /**
     * Refuse Blueprint sessions that name any artifact other than the exact retained draft.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testBlueprintSessionCannotResolveAnotherArtifact(): void
    {
        $source = new ContentStudioPreviewBindingSource($this->content(), $this->contexts());
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
        $source = new ContentStudioPreviewBindingSource($this->content(), $this->contexts());

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
        $source = new ContentStudioPreviewBindingSource($this->content(), $this->contexts([$key => $blank]));

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
     * Build the real projection boundary over inert persistence collaborators.
     *
     * @return  StudioContentProjectionService  Normally constructed projection boundary.
     *
     * @since  2.0.0
     */
    private function content(): StudioContentProjectionService
    {
        return new StudioContentProjectionService(
            new ContentModelService(
                $this->createStub(ContentModelRepository::class),
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
            $this->createStub(ContentProjectionBindingRepository::class),
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
     *
     * @return  ContentStudioAuthoringContextAuthority  Normally constructed authority.
     *
     * @since  2.0.0
     */
    private function contexts(array $bindings = []): ContentStudioAuthoringContextAuthority
    {
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
                $this->createStub(ContentModelRepository::class),
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
