<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http\Handler;

use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\App\Tests\Support\InterfaceTranslation;
use DateTimeImmutable;
use Kumwe\App\Administrator\Http\Handler\AdministratorLoginHandler;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\Access\AuthorizationDenied;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\Administration\CreatedAdministratorSession;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;

#[CoversClass(AdministratorLoginHandler::class)]
final class AdministratorLoginHandlerTest extends TestCase
{
    public function testCreatesAdministratorSessionInConfiguredSiteContext(): void
    {
        $principal = AuthorizationContext::principal(['administrator.access']);
        $identities = $this->createStub(AdministratorIdentityGateway::class);
        $identities->method('authenticate')->willReturn($principal);
        $sessions = $this->createMock(AdministratorSessionStore::class);
        $sessions->expects(self::once())->method('create')->with(
            self::callback(static fn (ExecutionContext $context): bool =>
                $context->site()->identifier() === 'corporate'),
            'Kumwe test browser',
        )->willReturn(new CreatedAdministratorSession(
            'opaque-session-token',
            new AdministratorSession(
                '018f22e2-7c8b-7ab0-8f3a-88e8026bb711',
                $principal,
                'csrf-token',
                new DateTimeImmutable('+1 hour'),
            ),
        ));
        $handler = new AdministratorLoginHandler(
            $identities,
            $sessions,
            $this->renderer(),
            InterfaceTranslation::translator(),
            false,
            3600,
            SiteContext::fromString('corporate'),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/login')
            ->withHeader('User-Agent', 'Kumwe test browser')
            ->withCookieParams([AdministratorLoginHandler::LOGIN_CSRF_COOKIE_NAME => str_repeat('a', 43)])
            ->withParsedBody([
                'email' => 'owner@example.test',
                'password' => 'secret password',
                '_csrf' => str_repeat('a', 43),
            ]);

        $response = $handler->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/administrator', $response->getHeaderLine('Location'));
        self::assertStringContainsString('kumwe_administrator_login_csrf=;', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('Max-Age=0', $response->getHeader('Set-Cookie')[1]);
    }


    /**
     * A wrong credential re-renders the form at 401 with the catalogue's rejection, keeping the email.
     *
     * The rejection names both fields rather than the one that was wrong, so it cannot be used to
     * confirm that an address exists; the typed address survives, and the submitted password does
     * not reach the rendered page.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAWrongCredentialRerendersWithTheCatalogueRejection(): void
    {
        $identities = $this->createStub(AdministratorIdentityGateway::class);
        $identities->method('authenticate')->willReturn(null);

        $response = $this->handler($identities)->handle($this->submission());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = (string) $response->getBody();
        self::assertStringContainsString('The email address or password is incorrect.', $body);
        self::assertStringContainsString('owner@example.test', $body);
        self::assertStringNotContainsString('wrong password', $body);
    }

    /**
     * A submission whose address is not an address is answered on the form at 401, not as a 500.
     *
     * The gateway refuses to normalise an empty or over-long address before it looks anything up;
     * that refusal is a wrong credential from the visitor's side and must draw the same rejection as
     * a wrong password, with the typed value kept and nothing escaping to the error middleware.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMalformedAddressIsRefusedOnTheFormLikeAWrongCredential(): void
    {
        $identities = $this->createStub(AdministratorIdentityGateway::class);
        $identities->method('authenticate')->willThrowException(
            new \InvalidArgumentException('An email address must contain between 1 and 254 characters.'),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/login')
            ->withCookieParams([AdministratorLoginHandler::LOGIN_CSRF_COOKIE_NAME => str_repeat('a', 43)])
            ->withParsedBody(['email' => '', 'password' => '', '_csrf' => str_repeat('a', 43)]);

        $response = $this->handler($identities)->handle($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString(
            'The email address or password is incorrect.',
            (string) $response->getBody(),
        );
    }

    /**
     * A throttled address is refused at 429 with the shared wording and a Retry-After.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAThrottledAddressIsRefusedWithTheSharedWording(): void
    {
        $identities = $this->createStub(AdministratorIdentityGateway::class);
        $identities->method('authenticate')->willThrowException(new AuthenticationThrottled());

        $response = $this->handler($identities)->handle($this->submission());

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('900', $response->getHeaderLine('Retry-After'));
        self::assertStringContainsString(
            'Too many unsuccessful authentication attempts.',
            (string) $response->getBody(),
        );
    }

    /**
     * A correct credential whose identity may not administer is refused on the form, not as a document.
     *
     * `/administrator/login` is exempt from both the session and the authorization middleware, so the
     * themed denial those render can never fire for it and the handler owns every refusal on this route.
     * The session store's contract says opening a session may be denied; letting that escape produced a
     * bare `application/problem+json` body, which Firefox refuses to render as a page — the visitor was
     * shown the browser's own "there's a problem with this site" screen instead of being told anything.
     * The status stays 403 and no authenticated cookie is set; the form receives a fresh login token.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACredentialWithoutAdministratorAccessIsRefusedOnTheFormRatherThanAsAProblemDocument(): void
    {
        $identities = $this->createStub(AdministratorIdentityGateway::class);
        $identities->method('authenticate')->willReturn(AuthorizationContext::principal(['content.read']));
        $sessions = $this->createStub(AdministratorSessionStore::class);
        $sessions->method('create')->willThrowException(
            new AuthorizationDenied(
                AuthorizationContext::SUBJECT,
                'administrator.access',
                'administrator',
                '*',
                'corporate',
                'core.scoped-grants.v1',
                'no_matching_grant',
            ),
        );

        $response = $this->handler($identities, $sessions)->handle($this->submission());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringNotContainsString('kumwe_administrator=', $response->getHeaderLine('Set-Cookie'));
        $body = (string) $response->getBody();
        self::assertStringContainsString('This account is not permitted to use the administrator.', $body);
        self::assertStringContainsString('owner@example.test', $body);
        self::assertStringNotContainsString('wrong password', $body);
    }

    /**
     * An initial form binds its hidden token to a protected, host-only, short-lived cookie.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGetIssuesTheSameProtectedTokenToCookieAndForm(): void
    {
        $identities = $this->createMock(AdministratorIdentityGateway::class);
        $identities->expects(self::never())->method('authenticate');
        $response = $this->handler($identities, secure: true)->handle(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/administrator/login'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertMatchesRegularExpression(
            '/^kumwe_administrator_login_csrf=([A-Za-z0-9_-]{43}); Path=\/administrator\/login; '
                . 'Max-Age=600; HttpOnly; SameSite=Strict; Secure$/D',
            $response->getHeaderLine('Set-Cookie'),
        );
        $cookie = explode(';', $response->getHeaderLine('Set-Cookie'))[0];
        self::assertStringEndsWith(explode('=', $cookie, 2)[1], (string) $response->getBody());
    }

    /**
     * Missing, malformed and mismatched double-submit tokens refuse before any password lookup.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvalidLoginTokensNeverAuthenticateOrCreateASession(): void
    {
        $valid = str_repeat('a', 43);
        foreach (
            [[null, $valid], [$valid, null], ['short', 'short'], [$valid, 'short'],
            [$valid, str_repeat('b', 43)], [[], $valid], [$valid, []]] as [$cookie, $submitted]
        ) {
            $identities = $this->createMock(AdministratorIdentityGateway::class);
            $identities->expects(self::never())->method('authenticate');
            $sessions = $this->createMock(AdministratorSessionStore::class);
            $sessions->expects(self::never())->method('create');
            $response = $this->handler($identities, $sessions)->handle(
                $this->submission()
                    ->withCookieParams([AdministratorLoginHandler::LOGIN_CSRF_COOKIE_NAME => $cookie])
                    ->withParsedBody(['email' => 'owner@example.test', '_csrf' => $submitted]),
            );

            self::assertSame(403, $response->getStatusCode());
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertStringContainsString('Path=/administrator/login', $response->getHeaderLine('Set-Cookie'));
            self::assertStringNotContainsString('kumwe_administrator=', $response->getHeaderLine('Set-Cookie'));
        }
    }

    /**
     * Build the sign-in handler over a template that renders only the rejection and the address.
     *
     * @param   AdministratorIdentityGateway    $identities  Gateway deciding the credential's fate.
     * @param   ?AdministratorSessionStore      $sessions    Store deciding whether a session may open,
     *                                                       or null for one that always succeeds.
     * @param   bool                            $secure      Whether the configured origin uses HTTPS.
     *
     * @return  AdministratorLoginHandler  The handler as the container composes it.
     *
     * @since   2.0.0
     */
    private function handler(
        AdministratorIdentityGateway $identities,
        ?AdministratorSessionStore $sessions = null,
        bool $secure = false,
    ): AdministratorLoginHandler {
        return new AdministratorLoginHandler(
            $identities,
            $sessions ?? $this->createStub(AdministratorSessionStore::class),
            new AdministratorRenderer(
                new AdministratorTwigEnvironment(new ArrayLoader([
                    'login.twig' => '{{ error|default("") }}|{{ email }}|{{ login_csrf }}',
                ])),
                new RecoveryAdministratorRenderer(new RecoveryAdministratorTwigEnvironment(new ArrayLoader())),
                new DeterministicCanonicalEncoder(),
            ),
            InterfaceTranslation::translator(),
            $secure,
            3600,
            SiteContext::fromString('corporate'),
        );
    }

    /**
     * A posted sign-in carrying an address and a password.
     *
     * @return  \Psr\Http\Message\ServerRequestInterface  The submission under test.
     *
     * @since   2.0.0
     */
    private function submission(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/login')
            ->withCookieParams([AdministratorLoginHandler::LOGIN_CSRF_COOKIE_NAME => str_repeat('a', 43)])
            ->withParsedBody([
                'email' => 'owner@example.test',
                'password' => 'wrong password',
                '_csrf' => str_repeat('a', 43),
            ]);
    }

    private function renderer(): AdministratorRenderer
    {
        return new AdministratorRenderer(
            new AdministratorTwigEnvironment(new ArrayLoader()),
            new RecoveryAdministratorRenderer(new RecoveryAdministratorTwigEnvironment(new ArrayLoader())),
            new DeterministicCanonicalEncoder(),
        );
    }
}
