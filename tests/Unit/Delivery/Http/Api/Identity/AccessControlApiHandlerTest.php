<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Identity;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Application\Security\HighImpactCredentialGuard;
use Kumwe\App\Delivery\Http\Api\Identity\AccessControlApiHandler;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Identity\Application\Administration\AccessControlRepository;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Security\PasswordHasher;
use Kumwe\App\Identity\Application\StepUp\StepUpCredentialStore;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;
use Kumwe\App\Tests\Support\MovableAuditClock;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Pins the security-timeline read and the three credential-recovery writes of the identity REST adapter.
 *
 * The real `AccessControlService` runs over a repository stub, so the installation-wide `users.manage` gate,
 * the self-reset refusal and the mandatory operator reason are the service's own. What is pinned here is the
 * route-to-operation mapping, the body grammar and that each refusal of input is one 422 problem.
 *
 * @since  2.0.0
 */
#[CoversClass(AccessControlApiHandler::class)]
final class AccessControlApiHandlerTest extends TestCase
{
    /**
     * Subject every recovery case addresses; never the acting administrator.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string SUBJECT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    /**
     * The security-event timeline is the service's closed projection, uncached.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSecurityEventsAreTheServicesClosedProjection(): void
    {
        $events = [[
            'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb304',
            'occurred_at' => '2026-09-24T10:00:00+00:00',
            'actor_id' => null,
            'action' => 'user.create',
            'subject_type' => 'user',
            'subject_id' => self::SUBJECT,
            'outcome' => 'success',
        ]];
        $repository = $this->createStub(AccessControlRepository::class);
        $repository->method('securityEvents')->willReturn($events);

        $response = $this->handle($repository, 'GET', '/api/v1/security-events');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['items' => $events], json_decode((string) $response->getBody(), true));
    }

    /**
     * The account-takeover recovery acts are not served: the browser performs them only behind a human step-up.
     *
     * Even with a well-formed body and `users.manage`, the handler resolves no operation for them, so the
     * store is never reached; in the kernel no route matches at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStepUpGatedRecoveryActsAreNotServed(): void
    {
        $repository = $this->createMock(AccessControlRepository::class);
        $repository->expects(self::never())->method('changePassword');
        $repository->expects(self::never())->method('advanceSecurityEpoch');
        foreach (
            [
                ['/password-reset', '{"password":"a replacement passphrase","reason":"lost device"}'],
                ['/step-up/revoke', '{"reason":"lost device"}'],
                ['/sessions/terminate', '{"reason":"lost device"}'],
            ] as [$suffix, $body]
        ) {
            $response = $this->handle(
                $repository,
                'POST',
                '/api/v1/users/' . self::SUBJECT . $suffix,
                $body,
            );

            self::assertSame(422, $response->getStatusCode(), $suffix);
            self::assertStringContainsString(
                'The identity operation is not supported.',
                (string) $response->getBody(),
            );
        }
    }

    /**
     * Run one request through a handler over the real access-control service.
     *
     * @param   AccessControlRepository  $repository  Repository stub the service reads and writes.
     * @param   string                   $method      HTTP method.
     * @param   string                   $path        Request path.
     * @param   string                   $body        Raw JSON body.
     *
     * @return  ResponseInterface  Handler response.
     *
     * @since   2.0.0
     */
    private function handle(
        AccessControlRepository $repository,
        string $method,
        string $path,
        string $body = '',
    ): ResponseInterface {
        $service = new AccessControlService(
            $repository,
            $this->createStub(PasswordHasher::class),
            new ImmediateTransactionManager(),
            new RecordingAuditRecorder(),
            new MovableAuditClock(new DateTimeImmutable('2026-09-24T10:00:00+00:00')),
            AuthorizationContext::gateway(),
            AuthorizationContext::ownershipWriter(),
            $this->createStub(HighImpactCredentialGuard::class),
            $this->createStub(StepUpCredentialStore::class),
            $this->createStub(AdministratorSessionStore::class),
            new DeterministicCanonicalEncoder(),
        );
        $identities = $this->createStub(AdministratorIdentityGateway::class);
        $principal = AuthorizationContext::principal(['users.manage']);
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'https://kumwe.test' . $path)
            ->withBody((new StreamFactory())->createStream($body))
            ->withAttribute('id', explode('/', $path)[4] ?? null)
            ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
            ->withAttribute(ExecutionContextAttribute::NAME, $principal->context(
                SiteContext::default(),
                AuthenticationStrength::BearerToken,
                'access-control-api-test',
            ));

        return (new AccessControlApiHandler($service, $identities, new ProblemDetailsResponseFactory()))
            ->handle($request);
    }
}
