<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http\Handler;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Administrator\Http\Handler\AdministratorLogoutHandler;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\Context\Value\ExecutionContext;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Pins administrator sign-out: the stored session dies, and the clearing cookie repeats the sign-in's attributes.
 *
 * A sign-out that only expired the cookie would leave a copied token redeemable, and a clearing cookie
 * whose attributes differ from the sign-in cookie would leave the browser's original in place. Both
 * halves are asserted exactly, in both transport postures, together with the refusals that keep the
 * route from running outside the authenticated pipeline.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorLogoutHandler::class)]
final class AdministratorLogoutHandlerTest extends TestCase
{
    /**
     * UUID of the session the authenticated request carries.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string SESSION = '018f22e2-7c8b-7ab0-8f3a-88e8026bb311';

    /**
     * Sign-out deletes exactly the request's session under its own context, then clears the cookie over HTTPS.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeletesTheRequestsOwnSessionAndClearsTheSecureCookie(): void
    {
        $context = AuthorizationContext::human(['administrator.access']);
        $sessions = $this->createMock(AdministratorSessionStore::class);
        $sessions->expects(self::once())->method('delete')->with(
            self::callback(static fn (ExecutionContext $deleting): bool => $deleting === $context),
            self::SESSION,
        );

        $response = (new AdministratorLogoutHandler($sessions, true))->handle($this->request($context));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/administrator/login', $response->getHeaderLine('Location'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(
            ['kumwe_administrator=deleted; Path=/administrator; Max-Age=0; HttpOnly; SameSite=Strict; Secure'],
            $response->getHeader('Set-Cookie'),
        );
    }

    /**
     * On a plain-HTTP deployment the clearing cookie omits `Secure`, matching the cookie the sign-in set.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheClearingCookieMirrorsAPlainHttpSignIn(): void
    {
        $sessions = $this->createMock(AdministratorSessionStore::class);
        $sessions->expects(self::once())->method('delete');

        $response = (new AdministratorLogoutHandler($sessions, false))->handle(
            $this->request(AuthorizationContext::human(['administrator.access'])),
        );

        self::assertSame(
            ['kumwe_administrator=deleted; Path=/administrator; Max-Age=0; HttpOnly; SameSite=Strict'],
            $response->getHeader('Set-Cookie'),
        );
        self::assertStringNotContainsString('Expires=', $response->getHeaderLine('Set-Cookie'));
    }

    /**
     * A request that reached the handler without a session is refused before anything is deleted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARequestWithoutASessionIsRefusedBeforeAnyDeletion(): void
    {
        $sessions = $this->createMock(AdministratorSessionStore::class);
        $sessions->expects(self::never())->method('delete');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/logout')
            ->withAttribute(ExecutionContextAttribute::NAME, AuthorizationContext::human(['administrator.access']));

        try {
            (new AdministratorLogoutHandler($sessions, true))->handle($request);
            self::fail('Sign-out must not run without a session.');
        } catch (InvalidArgumentException $refusal) {
            self::assertSame('An administrator session is required.', $refusal->getMessage());
        }
    }

    /**
     * A request carrying a session but no execution context is refused just as firmly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARequestWithoutAnExecutionContextIsRefusedBeforeAnyDeletion(): void
    {
        $sessions = $this->createMock(AdministratorSessionStore::class);
        $sessions->expects(self::never())->method('delete');
        $request = $this->request(AuthorizationContext::human(['administrator.access']))
            ->withoutAttribute(ExecutionContextAttribute::NAME);

        try {
            (new AdministratorLogoutHandler($sessions, true))->handle($request);
            self::fail('Sign-out must not run without an execution context.');
        } catch (InvalidArgumentException $refusal) {
            self::assertSame('An administrator execution context is required.', $refusal->getMessage());
        }
    }

    /**
     * A store that refuses the deletion propagates its refusal instead of clearing the cookie regardless.
     *
     * Clearing the cookie over a session that still exists would tell the operator they signed out
     * while a copied token stayed redeemable; the failure has to surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStoreFailureIsNotMaskedByAClearedCookie(): void
    {
        $sessions = self::createStub(AdministratorSessionStore::class);
        $sessions->method('delete')->willThrowException(new \RuntimeException('The session row is locked.'));

        try {
            (new AdministratorLogoutHandler($sessions, true))->handle(
                $this->request(AuthorizationContext::human(['administrator.access'])),
            );
            self::fail('A failed deletion must surface.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The session row is locked.', $failure->getMessage());
        }
    }

    /**
     * Build an authenticated, CSRF-checked sign-out request as the pipeline would deliver it.
     *
     * @param   ExecutionContext  $context  Context the session middleware minted for the request.
     *
     * @return  ServerRequestInterface  POST to the sign-out route carrying session and context attributes.
     *
     * @since   2.0.0
     */
    private function request(ExecutionContext $context): ServerRequestInterface
    {
        $session = new AdministratorSession(
            self::SESSION,
            AuthorizationContext::principal(['administrator.access']),
            'csrf-token',
            new DateTimeImmutable('+1 hour'),
        );

        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/logout')
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $session)
            ->withAttribute(ExecutionContextAttribute::NAME, $context)
            ->withParsedBody(['_csrf' => 'csrf-token']);
    }
}
