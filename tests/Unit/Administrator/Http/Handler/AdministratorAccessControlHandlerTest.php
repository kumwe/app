<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Administrator\Http\Handler;

use DateTimeImmutable;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorAccessControlHandler;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\CMS\Application\Authorization\AuthenticatedSurface;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\BusinessSecurity\Application\Approval\StepUpProofConsumer;
use Kumwe\CMS\BusinessSecurity\Application\MembershipDirectory;
use Kumwe\CMS\Identity\Application\Administration\AccessControlRepository;
use Kumwe\CMS\Identity\Application\Administration\AccessControlService;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSession;
use Kumwe\CMS\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\CMS\Identity\Application\Administration\CreatedAdministratorSession;
use Kumwe\CMS\Identity\Application\Security\PasswordHasher;
use Kumwe\CMS\Identity\Application\StepUp\AdministratorStepUpProvider;
use Kumwe\CMS\Identity\Application\StepUp\AuthorizationStepUpProofAdapter;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\CMS\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Twig\Loader\ArrayLoader;

/**
 * Pins focused workspace state across administrator membership-context rotation.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorAccessControlHandler::class)]
final class AdministratorAccessControlHandlerTest extends TestCase
{
    /**
     * A context selection returns to the same selected assignment review after rotating the session.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContextSelectionPreservesFocusedWorkspaceStateInRedirect(): void
    {
        $sessionId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb711';
        $userId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';
        $roleId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb302';
        $selectedId = $userId . ':' . $roleId;
        $principal = AuthorizationContext::principal(['administrator.access', 'users.manage']);
        $context = $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'test-request-context-select',
            surface: AuthenticatedSurface::Administrator,
            sessionId: $sessionId,
        );
        $session = new AdministratorSession(
            $sessionId,
            $principal,
            'current-csrf-token',
            new DateTimeImmutable('2026-08-12T13:00:00+00:00'),
            SiteContext::default(),
        );
        $replacement = new CreatedAdministratorSession(
            'rotated-cookie-token',
            new AdministratorSession(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb712',
                $principal,
                'rotated-csrf-token',
                new DateTimeImmutable('2026-08-12T14:00:00+00:00'),
                SiteContext::default(),
            ),
        );
        $sessions = $this->createMock(AdministratorSessionStore::class);
        $sessions->expects(self::once())->method('selectMembership')->with(
            self::identicalTo($context),
            'acme',
            'operations',
            'Kumwe test browser',
        )->willReturn($replacement);
        $handler = $this->handler($sessions);
        $query = [
            'section' => 'assignments',
            'mode' => 'review',
            'id' => $selectedId,
        ];
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/access')
            ->withQueryParams($query)
            ->withHeader('User-Agent', 'Kumwe test browser')
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $session)
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $context)
            ->withParsedBody([
                'action' => 'context.select',
                'organization' => 'acme',
                'workspace' => 'operations',
            ]);

        $response = $handler->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(
            '/administrator/access?section=assignments&mode=review&id=' . rawurlencode($selectedId) . '&saved=1',
            $response->getHeaderLine('Location'),
        );
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString(
            'kumwe_administrator=rotated-cookie-token',
            $response->getHeaderLine('Set-Cookie'),
        );
    }

    /**
     * Binds a proof to the exact submitted change set without binding credential or CSRF values.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStepUpPurposeBindsExactPayloadAndResourceSnapshot(): void
    {
        $handler = $this->handler($this->createStub(AdministratorSessionStore::class));
        $method = new \ReflectionMethod($handler, 'stepUpPurpose');
        $first = $method->invoke($handler, 'grant.synchronize', [
            'action' => 'grant.synchronize',
            'role_id' => 'role-one',
            'grant_snapshot' => str_repeat('a', 64),
            'selected_capabilities' => 'content.update,content.publish',
            '_csrf' => 'csrf-one',
            'step_up_code' => '111111',
        ]);
        $sameChange = $method->invoke($handler, 'grant.synchronize', [
            'action' => 'grant.synchronize',
            'role_id' => 'role-one',
            'grant_snapshot' => str_repeat('a', 64),
            'selected_capabilities' => 'content.update,content.publish',
            '_csrf' => 'csrf-two',
            'step_up_code' => '222222',
        ]);
        $differentChange = $method->invoke($handler, 'grant.synchronize', [
            'action' => 'grant.synchronize',
            'role_id' => 'role-one',
            'grant_snapshot' => str_repeat('a', 64),
            'selected_capabilities' => 'content.update',
            '_csrf' => 'csrf-two',
            'step_up_code' => '222222',
        ]);

        self::assertIsString($first);
        self::assertMatchesRegularExpression(
            '/^identity\.access_control\.grant\.synchronize\.payload\.[a-f0-9]{64}$/D',
            $first,
        );
        self::assertSame($first, $sameChange);
        self::assertNotSame($first, $differentChange);
    }

    /**
     * Build the handler with production value objects and inert ports not reached by context selection.
     *
     * @param   AdministratorSessionStore  $sessions  Session port whose selection call is under test.
     *
     * @return  AdministratorAccessControlHandler  Fully typed handler fixture.
     *
     * @since   2.0.0
     */
    private function handler(AdministratorSessionStore $sessions): AdministratorAccessControlHandler
    {
        $access = new AccessControlService(
            $this->createStub(AccessControlRepository::class),
            $this->createStub(PasswordHasher::class),
            $this->createStub(TransactionManager::class),
            $this->createStub(AuditRecorder::class),
            $this->createStub(ClockInterface::class),
            $this->createStub(AuthorizationGateway::class),
            $this->createStub(ResourceSiteOwnershipWriter::class),
        );
        $renderer = new AdministratorRenderer(
            new AdministratorTwigEnvironment(new ArrayLoader()),
            new RecoveryAdministratorRenderer(
                new RecoveryAdministratorTwigEnvironment(new ArrayLoader()),
            ),
        );

        return new AdministratorAccessControlHandler(
            $access,
            $this->createStub(AdministratorIdentityGateway::class),
            $renderer,
            $sessions,
            $this->createStub(MembershipDirectory::class),
            $this->createStub(AdministratorStepUpProvider::class),
            new AuthorizationStepUpProofAdapter(),
            $this->createStub(StepUpProofConsumer::class),
            $this->createStub(TransactionManager::class),
            $this->createStub(ClockInterface::class),
            false,
            3600,
        );
    }
}
