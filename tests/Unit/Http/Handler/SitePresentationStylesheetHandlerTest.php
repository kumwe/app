<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Http\Handler;

use Kumwe\App\Http\Handler\SitePresentationStylesheetHandler;
use Kumwe\App\Presentation\Application\SitePresentation;
use Kumwe\App\Presentation\ContentPageRenderService;
use Kumwe\App\Presentation\SiteRenderer;
use Kumwe\App\Presentation\Twig\SiteTwigEnvironment;
use Kumwe\App\Site\Application\SiteSettings;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Twig\Loader\ArrayLoader;

/**
 * Pins the same-origin theme stylesheet route that replaced the inline `style` attribute on `<body>`.
 *
 * The route is what lets the content-security policy refuse every inline style source, so it has to
 * serve exactly the declarations the page used to carry, honour the per-menu scheme override, cache
 * correctly in both the versioned and the unversioned case, revalidate by digest, and refuse any
 * query it does not understand instead of interpreting it.
 *
 * @since  2.0.0
 */
#[CoversClass(SitePresentationStylesheetHandler::class)]
#[CoversClass(ContentPageRenderService::class)]
final class SitePresentationStylesheetHandlerTest extends TestCase
{
    /**
     * The default palette is served as one `body{}` rule of validated custom properties.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testServesTheDefaultPaletteAsCss(): void
    {
        $response = $this->handle('/presentation/theme.css');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/css; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
        self::assertSame('public, no-cache, must-revalidate', $response->getHeaderLine('Cache-Control'));
        $css = (string) $response->getBody();
        self::assertSame(self::expectedCss(null), $css);
        self::assertStringStartsWith('body{--site-navy-950:#07182d;--site-ink:#13233a;', $css);
        self::assertStringContainsString('--site-accent:#0c9189;', $css);
        self::assertSame('"' . hash('sha256', $css) . '"', $response->getHeaderLine('ETag'));
    }

    /**
     * A bound scheme override changes the palette exactly as it changes the rendered page.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAppliesTheSchemeOverrideThePageWasRenderedWith(): void
    {
        $response = $this->handle('/presentation/theme.css?scheme=ocean');

        self::assertSame(200, $response->getStatusCode());
        $css = (string) $response->getBody();
        self::assertSame(self::expectedCss('ocean'), $css);
        self::assertStringContainsString('--site-accent:#0777af;', $css);
        self::assertStringNotContainsString('#0c9189', $css);
    }

    /**
     * An unknown but well-formed scheme degrades to the site default, as the page itself does.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnknownSchemeServesTheDefaultPalette(): void
    {
        $response = $this->handle('/presentation/theme.css?scheme=retired_scheme');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::expectedCss(null), (string) $response->getBody());
    }

    /**
     * A link whose version equals the served digest earns an immutable year-long cache lifetime.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMatchingVersionIsCachedImmutablyAndAStaleOneIsNot(): void
    {
        $current = hash('sha256', self::expectedCss('ocean'));

        $fresh = $this->handle('/presentation/theme.css?scheme=ocean&v=' . $current);
        self::assertSame(200, $fresh->getStatusCode());
        self::assertSame('public, max-age=31536000, immutable', $fresh->getHeaderLine('Cache-Control'));

        $stale = $this->handle('/presentation/theme.css?scheme=ocean&v=' . str_repeat('0', 64));
        self::assertSame(200, $stale->getStatusCode());
        self::assertSame('public, no-cache, must-revalidate', $stale->getHeaderLine('Cache-Control'));
        self::assertSame(self::expectedCss('ocean'), (string) $stale->getBody());
    }

    /**
     * The page's own link, built by the render service, names the digest the handler serves.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePageLinkAndTheServedDigestAgree(): void
    {
        $href = ContentPageRenderService::themeStylesheetHref('ocean', self::expectedCss('ocean'));

        self::assertSame(
            '/presentation/theme.css?scheme=ocean&v=' . hash('sha256', self::expectedCss('ocean')),
            $href,
        );
        self::assertSame('public, max-age=31536000, immutable', $this->handle($href)->getHeaderLine('Cache-Control'));
        self::assertSame(
            '/presentation/theme.css?v=' . hash('sha256', self::expectedCss(null)),
            ContentPageRenderService::themeStylesheetHref(null, self::expectedCss(null)),
        );
        self::assertSame(
            '/presentation/theme.css?v=' . hash('sha256', self::expectedCss(null)),
            ContentPageRenderService::themeStylesheetHref('Not A Handle', self::expectedCss(null)),
            'A malformed override could never match a stored scheme, so the link omits it.',
        );
    }

    /**
     * A browser presenting the served digest is answered 304 without a body.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRevalidatesByDigest(): void
    {
        $digest = hash('sha256', self::expectedCss(null));

        $response = $this->handle('/presentation/theme.css', ['If-None-Match' => '"' . $digest . '"']);

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame('"' . $digest . '"', $response->getHeaderLine('ETag'));

        $mismatch = $this->handle('/presentation/theme.css', ['If-None-Match' => '"' . str_repeat('f', 64) . '"']);
        self::assertSame(200, $mismatch->getStatusCode());
    }

    /**
     * Any query the route does not understand is refused as not found rather than interpreted.
     *
     * @param   string  $query  Query string appended to the route.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('refusedQueries')]
    public function testRefusesAQueryItDoesNotUnderstand(string $query): void
    {
        $response = $this->handle('/presentation/theme.css?' . $query);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('', (string) $response->getBody());
    }

    /**
     * Name each refused query shape.
     *
     * @return  iterable<string, array{string}>  Named queries.
     *
     * @since   2.0.0
     */
    public static function refusedQueries(): iterable
    {
        yield 'an unknown parameter' => ['theme=ocean'];
        yield 'a scheme outside the handle grammar' => ['scheme=Ocean%20Blue'];
        yield 'a scheme starting with a digit' => ['scheme=1ocean'];
        yield 'a scheme carrying a path' => ['scheme=..%2Focean'];
        yield 'a scheme given twice as a list' => ['scheme%5B%5D=ocean'];
        yield 'a short version' => ['v=abc'];
        yield 'an uppercase version' => ['v=' . strtoupper(str_repeat('a', 64))];
        yield 'a version given as a list' => ['v%5B%5D=' . str_repeat('a', 64)];
    }

    /**
     * Drive the handler with a request for the given path and headers.
     *
     * @param   string                 $target   Path and query of the request.
     * @param   array<string, string>  $headers  Request headers.
     *
     * @return  ResponseInterface  The handler's answer.
     *
     * @since   2.0.0
     */
    private function handle(string $target, array $headers = []): ResponseInterface
    {
        $settings = self::createStub(SiteSettings::class);
        $settings->method('current')->willReturn([
            'site_name' => 'Kumwe',
            'presentation' => SitePresentation::defaults(),
            'search_indexing_enabled' => false,
        ]);
        $handler = new SitePresentationStylesheetHandler(new ContentPageRenderService(
            $settings,
            new SiteRenderer(new SiteTwigEnvironment(new ArrayLoader([]))),
        ));
        $uri = 'https://kumwe.test' . $target;
        $request = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $handler->handle($request);
    }

    /**
     * Spell the CSS the default settings produce for a scheme, from the presentation contract itself.
     *
     * @param   ?string  $scheme  Scheme override handle, or null for the site default.
     *
     * @return  string  The expected `body{...}` rule.
     *
     * @since   2.0.0
     */
    private static function expectedCss(?string $scheme): string
    {
        $variables = SitePresentation::from(SitePresentation::defaults())
            ->withSchemeOverride($scheme)
            ->toView()['css_variables'];
        self::assertIsArray($variables);
        $declarations = '';
        foreach ($variables as $property => $value) {
            self::assertIsString($value);
            $declarations .= $property . ':' . $value . ';';
        }

        return 'body{' . $declarations . '}';
    }
}
