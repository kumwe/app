<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Administrator\Http\Handler;

use DateTimeImmutable;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorDashboardHandler;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Content\Application\ContentModelRepository;
use Kumwe\CMS\Content\Application\ContentModelService;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Application\ContentRepository;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Domain\ContentEntry;
use Kumwe\CMS\Content\Domain\ContentStatus;
use Kumwe\CMS\Content\Domain\JsonSchemaValidator;
use Kumwe\CMS\Content\Domain\SchemaCompatibilityChecker;
use Kumwe\CMS\Workflow\Domain\Workflow;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSession;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardComposer;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardPreferenceService;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardView;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardWidget;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferencePolicy;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceResolver;
use Kumwe\CMS\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\CMS\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Kumwe\CMS\Tests\Support\DashboardPreferenceTestRuntime;
use Kumwe\CMS\Tests\Support\ImmediateTransactionManager;
use Kumwe\CMS\Tests\Support\InMemoryPresentationAccessGroupRepository;
use Kumwe\CMS\Tests\Support\InMemoryPresentationPreferenceRepository;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Loader\ArrayLoader;

#[CoversClass(AdministratorDashboardHandler::class)]
#[CoversClass(AdministratorRenderer::class)]
#[UsesClass(ContentService::class)]
#[UsesClass(ContentModelService::class)]
#[UsesClass(ContentEntry::class)]
#[UsesClass(ContentRecord::class)]
#[UsesClass(RecoveryAdministratorRenderer::class)]
#[UsesClass(DashboardComposer::class)]
#[UsesClass(DashboardPreferenceService::class)]
#[UsesClass(DashboardView::class)]
#[UsesClass(DashboardWidget::class)]
final class AdministratorDashboardHandlerTest extends TestCase
{
    public function testRendersForAnAccessOnlyActorWithoutTouchingTheContentStores(): void
    {
        $contentRepository = $this->createMock(ContentRepository::class);
        $contentRepository->expects(self::never())->method(self::anything());
        $modelRepository = $this->createMock(ContentModelRepository::class);
        $modelRepository->expects(self::never())->method(self::anything());
        $handler = new AdministratorDashboardHandler(
            $this->contentService($contentRepository),
            $this->modelService($modelRepository),
            $this->renderer(),
            $this->dashboard(),
            (new DashboardPreferenceTestRuntime())->service,
        );

        $response = $handler->handle($this->request(['administrator.access']));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('widgets:1', $body);
        self::assertStringContainsString('context:yes', $body);
        self::assertStringContainsString('summary:no', $body);
        self::assertStringContainsString('read:no', $body);
    }

    public function testSummarisesContentForAnActorHoldingContentRead(): void
    {
        $contentRepository = $this->createMock(ContentRepository::class);
        $contentRepository->expects(self::once())->method('all')->willReturn([]);
        $modelRepository = $this->createMock(ContentModelRepository::class);
        $modelRepository->expects(self::once())->method('contentTypes')->willReturn([]);
        $handler = new AdministratorDashboardHandler(
            $this->contentService($contentRepository),
            $this->modelService($modelRepository),
            $this->renderer(),
            $this->dashboard(),
            (new DashboardPreferenceTestRuntime())->service,
        );

        $response = $handler->handle($this->request(['administrator.access', 'content.read']));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('summary:yes', $body);
        self::assertStringContainsString('search:/administrator/content', $body);
        self::assertStringContainsString('shortcut:yes', $body);
        self::assertStringContainsString('read:yes', $body);
    }

    /**
     * Proves collection-level capability maps never become per-record editor authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecentContentNeverProjectsPerRecordDestinations(): void
    {
        $template = "{% for item in dashboard.widgets[1].data.items %}"
            . "{{ item.status }}:{{ item.status_tone }}:{{ item.href|default('none') }};{% endfor %}";
        $expectedStatuses = 'draft:neutral:%s;review:warning:%s;published:success:%s;trashed:danger:%s;';
        foreach ([false, true] as $canUpdate) {
            $records = $this->records();
            $contentRepository = $this->createMock(ContentRepository::class);
            $contentRepository->expects(self::once())->method('all')->willReturn($records);
            $modelRepository = $this->createMock(ContentModelRepository::class);
            $modelRepository->expects(self::once())->method('contentTypes')->willReturn([]);
            $handler = new AdministratorDashboardHandler(
                $this->contentService($contentRepository),
                $this->modelService($modelRepository),
                $this->renderer($template),
                $this->dashboard(),
                (new DashboardPreferenceTestRuntime())->service,
            );
            $capabilities = ['administrator.access', 'content.read'];
            if ($canUpdate) {
                $capabilities[] = 'content.update';
            }

            $response = $handler->handle($this->request($capabilities));
            $destinations = array_fill(0, count($records), 'none');

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(vsprintf($expectedStatuses, $destinations), (string) $response->getBody());
        }
    }

    /**
     * Proves redirects can expose only the handler's closed preference-result vocabulary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRendersClosedPreferenceResultNotices(): void
    {
        $contentRepository = $this->createMock(ContentRepository::class);
        $contentRepository->expects(self::never())->method(self::anything());
        $modelRepository = $this->createMock(ContentModelRepository::class);
        $modelRepository->expects(self::never())->method(self::anything());
        $template = "saved:{{ dashboard.preference_saved ? 'yes' : 'no' }};"
            . "error:{{ dashboard.preference_error|default('none', true) }};"
            . "open:{{ dashboard.preference_open ? 'yes' : 'no' }}";
        $handler = new AdministratorDashboardHandler(
            $this->contentService($contentRepository),
            $this->modelService($modelRepository),
            $this->renderer($template),
            $this->dashboard(),
            (new DashboardPreferenceTestRuntime())->service,
        );
        $cases = [
            [['dashboard-saved' => '1'], 'saved:yes;error:none;open:yes'],
            [
                ['dashboard-error' => 'conflict'],
                'saved:no;error:core.interface_standard.dashboard.conflict_notice;open:yes',
            ],
            [
                ['dashboard-error' => 'invalid'],
                'saved:no;error:core.interface_standard.dashboard.invalid_notice;open:yes',
            ],
        ];

        foreach ($cases as [$query, $expected]) {
            $response = $handler->handle($this->request(['administrator.access'], $query));

            self::assertSame($expected, (string) $response->getBody());
        }
    }

    /**
     * Build the request the session and authorization middleware would hand the dashboard.
     *
     * @param   list<string>           $capabilities  Global capability names the signed-in actor holds.
     * @param   array<string, string>  $query         Closed preference-result query flags.
     *
     * @return  ServerRequestInterface  Request carrying session and execution-context attributes.
     *
     * @since   2.0.0
     */
    private function request(array $capabilities, array $query = []): ServerRequestInterface
    {
        $principal = AuthorizationContext::principal($capabilities);
        $session = new AdministratorSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
            $principal,
            'csrf-token',
            new DateTimeImmutable('+1 hour'),
        );

        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator')
            ->withQueryParams($query)
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $session)
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $principal->context(
                SiteContext::default(),
                AuthenticationStrength::Password,
                'test-request-0001',
            ));
    }

    /**
     * Compose a real content service over the repository double under test.
     *
     * @param   ContentRepository  $repository  Store double asserting which reads actually happen.
     *
     * @return  ContentService  Service wired to the canonical test authorization gateway.
     *
     * @since   2.0.0
     */
    private function contentService(ContentRepository $repository): ContentService
    {
        return new ContentService(
            $repository,
            $this->createStub(AuditRecorder::class),
            new ImmediateTransactionManager(),
            $this->clock(),
            new Workflow(),
            AuthorizationContext::gateway(),
            AuthorizationContext::ownershipWriter(),
        );
    }

    /**
     * Compose a real content-model service over the repository double under test.
     *
     * @param   ContentModelRepository  $repository  Store double asserting which reads actually happen.
     *
     * @return  ContentModelService  Service wired to the canonical test authorization gateway.
     *
     * @since   2.0.0
     */
    private function modelService(ContentModelRepository $repository): ContentModelService
    {
        return new ContentModelService(
            $repository,
            new JsonSchemaValidator(),
            new SchemaCompatibilityChecker(),
            AuthorizationContext::gateway(),
            AuthorizationContext::ownershipWriter(),
            $this->createStub(AuditRecorder::class),
            new ImmediateTransactionManager(),
            $this->clock(),
        );
    }

    /**
     * Build a renderer whose dashboard template echoes the values the assertions read.
     *
     * @return  AdministratorRenderer  Renderer resolving `dashboard.twig` from an in-memory loader.
     *
     * @since   2.0.0
     */
    private function renderer(?string $template = null): AdministratorRenderer
    {
        $template ??= 'widgets:{{ dashboard.widgets|length }};'
            . "summary:{{ 'core.dashboard.content-summary' in dashboard.selected_widget_ids ? 'yes' : 'no' }};"
            . "context:{{ 'core.dashboard.administrator-context' in dashboard.selected_widget_ids ? 'yes' : 'no' }};"
            . 'search:{{ dashboard.widgets[0].data.search.action|default(\'none\') }};'
            . "shortcut:{{ 'core.content' in dashboard.selected_shortcut_ids ? 'yes' : 'no' }};"
            . "read:{{ capabilities['content.read'] is defined ? 'yes' : 'no' }}";

        return new AdministratorRenderer(
            new AdministratorTwigEnvironment(new ArrayLoader(['dashboard.twig' => $template])),
            new RecoveryAdministratorRenderer(
                new RecoveryAdministratorTwigEnvironment(new ArrayLoader()),
            ),
        );
    }

    /**
     * Build the real group-aware dashboard composer over empty in-memory preference projections.
     *
     * @return  DashboardComposer  Composer exercising the production selection and filtering path.
     *
     * @since   2.0.0
     */
    private function dashboard(): DashboardComposer
    {
        $policy = $this->createStub(PresentationPreferencePolicy::class);
        $policy->method('allows')->willReturn(true);

        return new DashboardComposer(
            new PresentationPreferenceResolver(
                new InMemoryPresentationPreferenceRepository(),
                $policy,
            ),
            new InMemoryPresentationAccessGroupRepository(),
        );
    }

    /**
     * Build live, review, published, and trashed content activity rows.
     *
     * @return  list<ContentRecord>  Deterministic records covering every dashboard status presentation.
     *
     * @since   2.0.0
     */
    private function records(): array
    {
        $at = new DateTimeImmutable('2026-08-12T00:00:00+00:00');
        $statuses = [
            ContentStatus::Draft,
            ContentStatus::Review,
            ContentStatus::Published,
            ContentStatus::Draft,
        ];

        return array_map(
            static fn (ContentStatus $status, int $index): ContentRecord => new ContentRecord(
                ContentEntry::create(
                    '018f22e2-7c8b-7ab0-8f3a-88e8026bb4' . (string) (10 + $index),
                    'Dashboard item ' . (string) ($index + 1),
                    'dashboard-item-' . (string) ($index + 1),
                    status: $status,
                ),
                ContentService::CORE_PAGE_TYPE_ID,
                ContentService::CORE_WORKFLOW_ID,
                $at,
                $at,
                $index === 3 ? $at : null,
            ),
            $statuses,
            array_keys($statuses),
        );
    }

    /**
     * Supply the fixed instant the composed services stamp onto anything they would write.
     *
     * @return  ClockInterface  Clock pinned to one deterministic instant.
     *
     * @since   2.0.0
     */
    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-12T00:00:00+00:00'));

        return $clock;
    }
}
