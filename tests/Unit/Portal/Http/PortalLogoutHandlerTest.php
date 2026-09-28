<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Portal\Http;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Portal\Application\PortalContext;
use Kumwe\App\Portal\Application\PortalSession;
use Kumwe\App\Portal\Application\PortalSessionIdentity;
use Kumwe\App\Portal\Application\PortalSessionStore;
use Kumwe\App\Portal\Http\Handler\PortalLogoutHandler;
use Kumwe\Context\Value\SiteContext;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Pins portal sign-out: only the portal session dies, and only the portal cookie is cleared.
 *
 * The portal and the administrator hold independent sessions and cookies. Signing out of the portal
 * must delete exactly the portal row its owner presented and answer with a clearing cookie confined to
 * `/portal`, repeating the sign-in's attributes so the browser actually replaces it — and must never
 * touch the administrator cookie that may share the browser.
 *
 * @since  2.0.0
 */
#[CoversClass(PortalLogoutHandler::class)]
final class PortalLogoutHandlerTest extends TestCase
{
    /**
     * Subject UUID of the signed-in member.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string MEMBER = '018f0000-0000-7000-8000-000000000001';

    /**
     * UUID of the portal session the request carries.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string SESSION = '018f0000-0000-7000-8000-000000000002';

    /**
     * Sign-out deletes the presented session for its owner and clears only the portal cookie over HTTPS.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDeletesTheOwnersSessionAndClearsOnlyThePortalCookie(): void
    {
        $sessions = $this->createMock(PortalSessionStore::class);
        $sessions->expects(self::once())->method('delete')->with(self::SESSION, self::MEMBER);

        $response = (new PortalLogoutHandler($sessions, true))->handle($this->request());

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/portal/login', $response->getHeaderLine('Location'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(
            ['kumwe_portal=; Path=/portal; Max-Age=0; HttpOnly; SameSite=Strict; Secure'],
            $response->getHeader('Set-Cookie'),
        );
        self::assertStringNotContainsString('kumwe_administrator', $response->getHeaderLine('Set-Cookie'));
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
        $sessions = $this->createMock(PortalSessionStore::class);
        $sessions->expects(self::once())->method('delete');

        $response = (new PortalLogoutHandler($sessions, false))->handle($this->request());

        self::assertSame(
            ['kumwe_portal=; Path=/portal; Max-Age=0; HttpOnly; SameSite=Strict'],
            $response->getHeader('Set-Cookie'),
        );
    }

    /**
     * A request that reached the handler without a portal session is refused before anything is deleted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARequestWithoutAPortalSessionIsRefusedBeforeAnyDeletion(): void
    {
        $sessions = $this->createMock(PortalSessionStore::class);
        $sessions->expects(self::never())->method('delete');
        $request = new ServerRequest([], [], new Uri('https://example.test/portal/logout'), 'POST');

        try {
            (new PortalLogoutHandler($sessions, true))->handle($request);
            self::fail('Sign-out must not run without a portal session.');
        } catch (InvalidArgumentException $refusal) {
            self::assertSame('A portal session is required.', $refusal->getMessage());
        }
    }

    /**
     * A store that refuses the deletion propagates its refusal instead of clearing the cookie regardless.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStoreFailureIsNotMaskedByAClearedCookie(): void
    {
        $sessions = self::createStub(PortalSessionStore::class);
        $sessions->method('delete')->willThrowException(
            new \RuntimeException('The portal session changed during deletion.'),
        );

        try {
            (new PortalLogoutHandler($sessions, true))->handle($this->request());
            self::fail('A failed deletion must surface.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The portal session changed during deletion.', $failure->getMessage());
        }
    }

    /**
     * Build an authenticated portal sign-out request as the pipeline would deliver it.
     *
     * @return  ServerRequestInterface  POST to the portal sign-out route carrying the session attribute.
     *
     * @since   2.0.0
     */
    private function request(): ServerRequestInterface
    {
        $principal = AuthenticatedPrincipal::issueFromStrings(new \stdClass(), self::MEMBER, ['portal.access']);
        $now = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
        $session = new PortalSession(
            self::SESSION,
            new PortalSessionIdentity($principal, new PortalContext(SiteContext::default(), null), 1),
            str_repeat('c', 43),
            $now,
            null,
            $now->modify('+1 hour'),
        );

        return (new ServerRequest([], [], new Uri('https://example.test/portal/logout'), 'POST'))
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $session)
            ->withParsedBody(['_csrf' => str_repeat('c', 43)]);
    }
}
