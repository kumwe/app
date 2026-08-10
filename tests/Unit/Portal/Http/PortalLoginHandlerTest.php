<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Portal\Http;

use Kumwe\CMS\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\CMS\Extension\Contribution\CapabilityDefinitionRegistry;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Portal\Application\CreatedPortalSession;
use Kumwe\CMS\Portal\Application\PortalAuthenticator;
use Kumwe\CMS\Portal\Application\PortalContextResolver;
use Kumwe\CMS\Portal\Application\PortalPasswordIdentity;
use Kumwe\CMS\Portal\Application\PortalSession;
use Kumwe\CMS\Portal\Application\PortalSessionStore;
use Kumwe\CMS\Portal\Contribution\PortalNavigationRegistry;
use Kumwe\CMS\Portal\Contribution\PortalTemplateRegistry;
use Kumwe\CMS\Portal\Contribution\PortalWorkspaceRegistry;
use Kumwe\CMS\Portal\Domain\PortalContext;
use Kumwe\CMS\Portal\Http\Handler\PortalLoginHandler;
use Kumwe\CMS\Portal\Presentation\PortalNavigationVisibility;
use Kumwe\CMS\Portal\Presentation\PortalRenderer;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[CoversClass(PortalLoginHandler::class)]
final class PortalLoginHandlerTest extends TestCase
{
    private const LOGIN_CSRF = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';

    public function testGetIssuesAProtectedLoginTokenAndRendersItsHiddenValue(): void
    {
        $response = $this->handler(new RejectingPortalAuthenticator(), true)->handle(
            new ServerRequest([], [], new Uri('https://example.test/portal/login'), 'GET'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertMatchesRegularExpression(
            '/^kumwe_portal_login_csrf=[A-Za-z0-9_-]{43}; Path=\/portal\/login; Max-Age=600; '
                . 'HttpOnly; SameSite=Strict; Secure$/D',
            $response->getHeaderLine('Set-Cookie'),
        );
        self::assertStringEndsWith('|43', (string) $response->getBody());
    }

    public function testForgedLoginTokenIsRejectedBeforeAuthenticationAndRotated(): void
    {
        $authenticator = new CountingPortalAuthenticator();
        $request = $this->request('member@example.test')->withCookieParams([]);
        $response = $this->handler($authenticator)->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $authenticator->attempts);
        self::assertStringContainsString('portal security token is invalid', (string) $response->getBody());
        self::assertStringContainsString(
            'kumwe_portal_login_csrf=',
            $response->getHeaderLine('Set-Cookie'),
        );
        self::assertStringNotContainsString('kumwe_portal=', $response->getHeaderLine('Set-Cookie'));
    }

    public function testCredentialsAndUnavailableMembershipUseTheSameNonEnumeratingResponse(): void
    {
        $email = 'member@example.test';
        $invalidCredentials = $this->handler(new RejectingPortalAuthenticator())->handle($this->request($email));
        $unavailableMembership = $this->handler(
            new AcceptedPortalAuthenticator(),
        )->handle($this->request($email));

        self::assertSame(401, $invalidCredentials->getStatusCode());
        self::assertSame($invalidCredentials->getStatusCode(), $unavailableMembership->getStatusCode());
        self::assertSame((string) $invalidCredentials->getBody(), (string) $unavailableMembership->getBody());
        self::assertStringNotContainsString('membership', strtolower((string) $unavailableMembership->getBody()));
        self::assertStringNotContainsString('workspace', strtolower((string) $unavailableMembership->getBody()));
    }

    public function testMalformedWorkspaceHintCannotProduceASelectionOracle(): void
    {
        $email = 'member@example.test';
        $invalidCredentials = $this->handler(new RejectingPortalAuthenticator())->handle($this->request($email));
        $malformedHint = $this->handler(new AcceptedPortalAuthenticator())->handle(
            $this->request($email, '../other-organization'),
        );

        self::assertSame(401, $malformedHint->getStatusCode());
        self::assertSame((string) $invalidCredentials->getBody(), (string) $malformedHint->getBody());
    }

    private function handler(PortalAuthenticator $authenticator, bool $secure = false): PortalLoginHandler
    {
        $capabilities = new CapabilityDefinitionRegistry();
        $navigation = new PortalNavigationRegistry(
            new PortalWorkspaceRegistry(),
            $capabilities,
            new AuthorizationPolicyRegistry(),
        );
        $renderer = new PortalRenderer(
            new Environment(new ArrayLoader([
                'portal/login.twig' => '{{ error }}|{{ email }}|{{ login_csrf|length }}',
            ]), ['strict_variables' => true]),
            $navigation,
            new PortalTemplateRegistry(),
            $this->createStub(PortalNavigationVisibility::class),
        );

        return new PortalLoginHandler(
            $authenticator,
            new DenyingPortalContextResolver(),
            new UnusedPortalSessionStore(),
            $renderer,
            $secure,
            3600,
        );
    }

    private function request(string $email, string $workspace = ''): ServerRequest
    {
        return (new ServerRequest([], [], new Uri('https://example.test/portal/login'), 'POST'))
            ->withCookieParams([PortalLoginHandler::LOGIN_CSRF_COOKIE_NAME => self::LOGIN_CSRF])
            ->withParsedBody([
                'email' => $email,
                'password' => 'correct horse battery staple',
                'workspace' => $workspace,
                '_csrf' => self::LOGIN_CSRF,
            ]);
    }
}

final readonly class RejectingPortalAuthenticator implements PortalAuthenticator
{
    public function authenticate(string $email, string $password, string $source): ?PortalPasswordIdentity
    {
        return null;
    }
}

final readonly class AcceptedPortalAuthenticator implements PortalAuthenticator
{
    public function authenticate(string $email, string $password, string $source): ?PortalPasswordIdentity
    {
        return new PortalPasswordIdentity(
            AuthenticatedPrincipal::issueFromStrings(
                new \stdClass(),
                '018f0000-0000-7000-8000-000000000001',
                ['portal.access'],
            ),
            1,
        );
    }
}

final class CountingPortalAuthenticator implements PortalAuthenticator
{
    public int $attempts = 0;

    public function authenticate(string $email, string $password, string $source): ?PortalPasswordIdentity
    {
        ++$this->attempts;
        return null;
    }
}

final readonly class DenyingPortalContextResolver implements PortalContextResolver
{
    public function resolve(AuthenticatedPrincipal $principal, ?string $workspaceHint): ?PortalContext
    {
        return null;
    }
}

final readonly class UnusedPortalSessionStore implements PortalSessionStore
{
    public function create(
        PortalPasswordIdentity $identity,
        PortalContext $context,
        string $userAgent,
    ): CreatedPortalSession {
        throw new \LogicException('A denied login must not create a portal session.');
    }

    public function find(string $cookieToken, string $userAgent): ?PortalSession
    {
        return null;
    }

    public function delete(string $sessionId, string $subjectId): void
    {
    }

    public function purgeExpired(): int
    {
        return 0;
    }
}
