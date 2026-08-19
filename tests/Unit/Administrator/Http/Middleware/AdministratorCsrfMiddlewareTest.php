<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http\Middleware;

use DateTimeImmutable;
use Kumwe\App\Administrator\Http\Middleware\AdministratorCsrfMiddleware;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\InterfaceTranslation;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Pins the administrator CSRF guard's refusal page: catalogue wording, and the negotiated language.
 *
 * The refusal is the one administrator page rendered without a template, so it is the one place the
 * language and direction of the response have to be emitted by hand. These tests hold both halves —
 * that the sentences come from the catalogue rather than from the class, and that `lang`, `dir` and
 * `Content-Language` follow the locale in flight rather than a hardcoded `en-GB`.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorCsrfMiddleware::class)]
final class AdministratorCsrfMiddlewareTest extends TestCase
{
    /**
     * A submission echoing the session token reaches the handler with the flattened form.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMatchingTokenReachesTheHandler(): void
    {
        $handler = new CapturingAdministratorHandler();
        $request = $this->request('csrf-token');

        $response = $this->middleware()->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['_csrf' => 'csrf-token'], $handler->request?->getParsedBody());
    }

    /**
     * A wrong token is refused with a self-contained page whose sentences come from the catalogue.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAWrongTokenIsRefusedWithCatalogueWording(): void
    {
        $handler = new CapturingAdministratorHandler();

        $response = $this->middleware()->process($this->request('not-the-token'), $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($handler->request);
        $body = (string) $response->getBody();
        self::assertStringContainsString('<title>Forbidden</title>', $body);
        self::assertStringContainsString('The administrator security token is invalid or expired.', $body);
        self::assertStringContainsString('<a href="/administrator">Return to Kumwe</a>', $body);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * An absent token is refused exactly as a wrong one is, rather than satisfying the comparison.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAbsentTokenIsRefusedRatherThanAccepted(): void
    {
        $handler = new CapturingAdministratorHandler();

        $response = $this->middleware()->process($this->request(null), $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($handler->request);
    }

    /**
     * The refusal page declares the negotiated locale, not a language fixed in the class.
     *
     * A right-to-left operator meeting this page has to meet it laid out correctly, which is the
     * whole reason the guard reads the active locale rather than emitting `en-GB` unconditionally.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRefusalPageDeclaresTheNegotiatedLanguageAndDirection(): void
    {
        $middleware = new AdministratorCsrfMiddleware(
            InterfaceTranslation::translator('he'),
            InterfaceTranslation::activeLocale('he'),
        );

        $response = $middleware->process($this->request('wrong'), new CapturingAdministratorHandler());

        $body = (string) $response->getBody();
        self::assertStringContainsString('<html lang="he" dir="rtl">', $body);
        self::assertSame('he', $response->getHeaderLine('Content-Language'));
    }

    /**
     * Build the guard over the repository's own compiled catalogue at the source locale.
     *
     * @return  AdministratorCsrfMiddleware  The guard as the container composes it.
     *
     * @since   2.0.0
     */
    private function middleware(): AdministratorCsrfMiddleware
    {
        return new AdministratorCsrfMiddleware(
            InterfaceTranslation::translator(),
            InterfaceTranslation::activeLocale(),
        );
    }

    /**
     * Build an administrator submission carrying a session and an optional token.
     *
     * @param   ?string  $token  Token the form echoes, or null to omit the field entirely.
     *
     * @return  ServerRequestInterface  Request bound to a session whose token is `csrf-token`.
     *
     * @since   2.0.0
     */
    private function request(?string $token): ServerRequestInterface
    {
        $session = new AdministratorSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb311',
            AuthorizationContext::principalFromGrantRows([[
                'capability' => 'administrator.access',
                'scope_type' => 'site',
                'scope_identifier' => 'default',
            ]]),
            'csrf-token',
            new DateTimeImmutable('+1 hour'),
        );

        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/settings')
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $session)
            ->withParsedBody($token === null ? [] : ['_csrf' => $token]);
    }
}

/** Records the request the guard forwarded, so a refusal can be told from a pass-through. */
final class CapturingAdministratorHandler implements RequestHandlerInterface
{
    /**
     * Request the guard let through, or null when it refused.
     *
     * @var    ?ServerRequestInterface
     * @since  2.0.0
     */
    public ?ServerRequestInterface $request = null;

    /**
     * Record the forwarded request and answer with an empty success.
     *
     * @param   ServerRequestInterface  $request  Request the guard forwarded.
     *
     * @return  ResponseInterface  A 200 with no body.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return new TextResponse('');
    }
}
