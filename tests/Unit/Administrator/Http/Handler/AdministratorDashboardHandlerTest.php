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
use Kumwe\CMS\Content\Application\ContentRepository;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Domain\JsonSchemaValidator;
use Kumwe\CMS\Content\Domain\SchemaCompatibilityChecker;
use Kumwe\CMS\Workflow\Domain\Workflow;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSession;
use Kumwe\CMS\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\CMS\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Kumwe\CMS\Tests\Support\ImmediateTransactionManager;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Loader\ArrayLoader;

#[CoversClass(AdministratorDashboardHandler::class)]
#[UsesClass(ContentService::class)]
#[UsesClass(ContentModelService::class)]
#[UsesClass(AdministratorRenderer::class)]
#[UsesClass(RecoveryAdministratorRenderer::class)]
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
        );

        $response = $handler->handle($this->request(['administrator.access']));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('total:0', $body);
        self::assertStringContainsString('types:0', $body);
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
        );

        $response = $handler->handle($this->request(['administrator.access', 'content.read']));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('total:0', $body);
        self::assertStringContainsString('read:yes', $body);
    }

    /**
     * Build the request the session and authorization middleware would hand the dashboard.
     *
     * @param   list<string>  $capabilities  Global capability names the signed-in actor holds.
     *
     * @return  ServerRequestInterface  Request carrying session and execution-context attributes.
     *
     * @since   2.0.0
     */
    private function request(array $capabilities): ServerRequestInterface
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
    private function renderer(): AdministratorRenderer
    {
        $template = 'total:{{ counts.total }};types:{{ content_types|length }};'
            . "read:{{ capabilities['content.read'] is defined ? 'yes' : 'no' }}";

        return new AdministratorRenderer(
            new AdministratorTwigEnvironment(new ArrayLoader(['dashboard.twig' => $template])),
            new RecoveryAdministratorRenderer(
                new RecoveryAdministratorTwigEnvironment(new ArrayLoader()),
            ),
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
