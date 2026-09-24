<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Portal\Http\Middleware;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Portal\Application\PortalContext;
use Kumwe\App\Portal\Application\PortalSession;
use Kumwe\App\Portal\Application\PortalSessionIdentity;
use Kumwe\App\Portal\Http\Middleware\PortalCsrfMiddleware;
use Kumwe\App\Tests\Support\InterfaceTranslation;
use Kumwe\Context\Value\SiteContext;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\Uri;
use Laminas\Diactoros\Response\TextResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Pins the portal CSRF guard: which tokens pass, which are refused, and what a refusal looks like.
 *
 * The guard is the only thing between a portal form post and its handler, so its rejection matrix is
 * held here in full: the session's own token passes from either the header or the field; a wrong, a
 * partial, an absent, a non-string, and an administrator token are all refused with the same
 * `no-store` 403 page; the header outranks the field so a stale field cannot rescue a wrong header; the
 * refusal page speaks the negotiated locale; and a request that reached the guard without a portal
 * session is refused outright rather than compared against nothing.
 *
 * @since  2.0.0
 */
#[CoversClass(PortalCsrfMiddleware::class)]
final class PortalCsrfMiddlewareTest extends TestCase
{
    /**
     * CSRF token the request's portal session carries.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string TOKEN = 'portal-csrf-token-0123456789abcdefghijklmnopqrstuvwxyz';

    /**
     * A field echoing the session token reaches the handler with the flattened form and the original body.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMatchingFieldReachesTheHandlerWithTheFlattenedForm(): void
    {
        $handler = new CapturingPortalCsrfHandler();
        $request = $this->request(['_csrf' => self::TOKEN, 'values' => ['name' => 'Nested'], 'plain' => 'kept']);

        $response = $this->middleware()->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        $forwarded = $handler->request;
        self::assertInstanceOf(ServerRequestInterface::class, $forwarded);
        self::assertSame(['_csrf' => self::TOKEN, 'plain' => 'kept'], $forwarded->getParsedBody());
        self::assertSame(
            ['_csrf' => self::TOKEN, 'values' => ['name' => 'Nested'], 'plain' => 'kept'],
            $forwarded->getAttribute(PortalCsrfMiddleware::ATTRIBUTE_PARSED_BODY),
        );
    }

    /**
     * A scripted post may carry the token in `X-CSRF-Token` instead of a field.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMatchingHeaderReachesTheHandler(): void
    {
        $handler = new CapturingPortalCsrfHandler();
        $request = $this->request(['plain' => 'kept'])->withHeader('X-CSRF-Token', self::TOKEN);

        self::assertSame(200, $this->middleware()->process($request, $handler)->getStatusCode());
        self::assertSame(['plain' => 'kept'], $handler->request?->getParsedBody());
    }

    /**
     * A present header outranks the field, so a correct field cannot rescue a wrong header.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAWrongHeaderIsNotRescuedByACorrectField(): void
    {
        $handler = new CapturingPortalCsrfHandler();
        $request = $this->request(['_csrf' => self::TOKEN])->withHeader('X-CSRF-Token', 'not-the-token');

        self::assertSame(403, $this->middleware()->process($request, $handler)->getStatusCode());
        self::assertNull($handler->request);
    }

    /**
     * Every token that is not exactly the session's is refused with the same page, and nothing is forwarded.
     *
     * @param   array<string, mixed>  $body  Parsed body carrying the candidate token, or none.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('refusedTokens')]
    public function testATokenThatIsNotTheSessionsIsRefused(array $body): void
    {
        $handler = new CapturingPortalCsrfHandler();

        $response = $this->middleware()->process($this->request($body), $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($handler->request);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('en-GB', $response->getHeaderLine('Content-Language'));
        $page = (string) $response->getBody();
        self::assertStringContainsString('<html lang="en-GB" dir="ltr">', $page);
        self::assertStringContainsString('<title>Forbidden</title>', $page);
        self::assertStringContainsString('The portal security token is invalid or expired.', $page);
        self::assertStringContainsString('<a href="/portal">', $page);
        self::assertStringNotContainsString(self::TOKEN, $page, 'The refusal never discloses the real token.');
    }

    /**
     * Name each refused candidate.
     *
     * @return  iterable<string, array{array<string, mixed>}>  Named bodies.
     *
     * @since   2.0.0
     */
    public static function refusedTokens(): iterable
    {
        yield 'a wrong token' => [['_csrf' => 'not-the-token']];
        yield 'an absent token' => [[]];
        yield 'an empty token' => [['_csrf' => '']];
        yield 'a prefix of the real token' => [['_csrf' => substr(self::TOKEN, 0, -1)]];
        yield 'the real token with a suffix' => [['_csrf' => self::TOKEN . 'x']];
        yield 'the real token in another case' => [['_csrf' => strtoupper(self::TOKEN)]];
        yield 'an array where a string is expected' => [['_csrf' => [self::TOKEN]]];
        yield 'an administrator session token' => [['_csrf' => 'administrator-csrf-token']];
    }

    /**
     * A body the pipeline did not parse is parsed from the raw stream rather than treated as empty.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnparsedUrlencodedBodyIsReadFromTheStream(): void
    {
        $handler = new CapturingPortalCsrfHandler();
        $stream = new Stream('php://memory', 'wb+');
        $stream->write(http_build_query(['_csrf' => self::TOKEN, 'plain' => 'kept', 'values' => ['a' => 'b']]));
        $stream->rewind();
        $request = $this->request([])->withParsedBody(null)->withBody($stream);

        self::assertSame(200, $this->middleware()->process($request, $handler)->getStatusCode());
        $forwarded = $handler->request;
        self::assertInstanceOf(ServerRequestInterface::class, $forwarded);
        self::assertSame(['_csrf' => self::TOKEN, 'plain' => 'kept'], $forwarded->getParsedBody());
        self::assertSame(
            ['_csrf' => self::TOKEN, 'plain' => 'kept', 'values' => ['a' => 'b']],
            $forwarded->getAttribute(PortalCsrfMiddleware::ATTRIBUTE_PARSED_BODY),
        );
    }

    /**
     * The refusal page declares the negotiated locale and direction, not a language fixed in the class.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRefusalPageDeclaresTheNegotiatedLanguageAndDirection(): void
    {
        $middleware = new PortalCsrfMiddleware(
            InterfaceTranslation::translator('he'),
            InterfaceTranslation::activeLocale('he'),
        );

        $response = $middleware->process($this->request(['_csrf' => 'wrong']), new CapturingPortalCsrfHandler());

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('<html lang="he" dir="rtl">', (string) $response->getBody());
        self::assertSame('he', $response->getHeaderLine('Content-Language'));
    }

    /**
     * A request that reached the guard without a portal session is refused outright, never compared.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARequestWithoutAPortalSessionIsRefusedOutright(): void
    {
        $handler = new CapturingPortalCsrfHandler();
        $request = $this->request(['_csrf' => self::TOKEN])->withoutAttribute(PortalSession::REQUEST_ATTRIBUTE);

        try {
            $this->middleware()->process($request, $handler);
            self::fail('The guard must not run without a portal session.');
        } catch (InvalidArgumentException $refusal) {
            self::assertSame('A portal session is required.', $refusal->getMessage());
        }
        self::assertNull($handler->request);
    }

    /**
     * Build the guard over the repository's own compiled catalogue at the source locale.
     *
     * @return  PortalCsrfMiddleware  The guard as the container composes it.
     *
     * @since   2.0.0
     */
    private function middleware(): PortalCsrfMiddleware
    {
        return new PortalCsrfMiddleware(InterfaceTranslation::translator(), InterfaceTranslation::activeLocale());
    }

    /**
     * Build a portal mutation carrying a live session whose token is `TOKEN`.
     *
     * @param   array<string, mixed>  $body  Parsed body of the submission.
     *
     * @return  ServerRequestInterface  POST bound to the session.
     *
     * @since   2.0.0
     */
    private function request(array $body): ServerRequestInterface
    {
        $principal = AuthenticatedPrincipal::issueFromStrings(
            new \stdClass(),
            '018f0000-0000-7000-8000-000000000001',
            ['portal.access'],
        );
        $now = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
        $session = new PortalSession(
            '018f0000-0000-7000-8000-000000000002',
            new PortalSessionIdentity($principal, new PortalContext(SiteContext::default(), null), 1),
            self::TOKEN,
            $now,
            null,
            $now->modify('+1 hour'),
        );

        return (new ServerRequest([], [], new Uri('https://example.test/portal/account'), 'POST'))
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $session)
            ->withParsedBody($body);
    }
}

/**
 * Records the request the guard forwarded, so a refusal can be told from a pass-through.
 *
 * @since  2.0.0
 */
final class CapturingPortalCsrfHandler implements RequestHandlerInterface
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
