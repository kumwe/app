<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\Http;

use Kumwe\App\Http\Handler\SitePresentationStylesheetHandler;
use Kumwe\App\Http\Middleware\SecurityHeadersMiddleware;
use Kumwe\App\Http\Security\SecurityHeaders;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Mezzio\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Proves through the live pipeline that no public response depends on an inline style source.
 *
 * The homepage must link its palette as a same-origin stylesheet and carry no `style` attribute, the
 * linked stylesheet must be served as CSS with the versioned immutable cache lifetime, and every
 * response's content-security policy must spell `style-src-attr 'none'` with no `'unsafe-inline'`
 * anywhere. The SVG media policy is the one deliberate exception and is pinned by the middleware's
 * own unit test.
 *
 * @since  2.0.0
 */
#[CoversClass(SitePresentationStylesheetHandler::class)]
#[CoversClass(SecurityHeadersMiddleware::class)]
#[CoversClass(SecurityHeaders::class)]
#[CoversClass(ContainerFactory::class)]
final class PublicThemeStylesheetTest extends TestCase
{
    /**
     * The homepage links the theme stylesheet, carries no inline style, and its policy admits none.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheHomepageLinksItsPaletteInsteadOfCarryingItInline(): void
    {
        $application = $this->application();

        $home = $this->handle($application, '/');
        self::assertSame(200, $home->getStatusCode());
        $html = (string) $home->getBody();
        preg_match(
            '/<link rel="stylesheet" href="(\/presentation\/theme\.css\?v=[a-f0-9]{64})" data-site-theme>/',
            $html,
            $link,
        );
        $href = $link[1] ?? null;
        self::assertIsString($href, 'The layout links the palette stylesheet.');
        self::assertDoesNotMatchRegularExpression('/<body[^>]*\sstyle=/', $html, 'The body carries no inline style.');
        self::assertStringNotContainsString(' style="', $html, 'No shipped public markup carries a style attribute.');
        $this->assertNoInlineStyleSource($home);

        $stylesheet = $this->handle($application, $href);
        self::assertSame(200, $stylesheet->getStatusCode());
        self::assertSame('text/css; charset=utf-8', $stylesheet->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=31536000, immutable', $stylesheet->getHeaderLine('Cache-Control'));
        $css = (string) $stylesheet->getBody();
        self::assertMatchesRegularExpression('/^body\{(--site-[a-z0-9-]+:#[a-f0-9]{6};)+\}$/D', $css);
        self::assertStringContainsString('--site-navy-950:', $css);
        self::assertStringContainsString('v=' . hash('sha256', $css), $href, 'The link names the served digest.');
        $this->assertNoInlineStyleSource($stylesheet);

        $revalidated = $this->handle($application, $href, ['If-None-Match' => $stylesheet->getHeaderLine('ETag')]);
        self::assertSame(304, $revalidated->getStatusCode());

        $refused = $this->handle($application, '/presentation/theme.css?scheme=Not%20A%20Scheme');
        self::assertSame(404, $refused->getStatusCode());
    }

    /**
     * The administrator sign-in page and a problem document share the same inline-free style policy.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministratorAndPortalResponsesAdmitNoInlineStyleSource(): void
    {
        $application = $this->application();

        foreach (['/administrator/login', '/portal/login', '/health/live'] as $path) {
            $response = $this->handle($application, $path);
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertStringNotContainsString(' style="', (string) $response->getBody(), $path);
            $this->assertNoInlineStyleSource($response);
        }
    }

    /**
     * Assert the response's content-security policy admits no inline style source of either kind.
     *
     * @param   ResponseInterface  $response  Response leaving the pipeline.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertNoInlineStyleSource(ResponseInterface $response): void
    {
        $policy = $response->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("style-src 'self'; style-src-attr 'none'; style-src-elem 'self'", $policy);
        self::assertStringNotContainsString("'unsafe-inline'", $policy);
        self::assertStringNotContainsString("'unsafe-hashes'", $policy);
    }

    /**
     * Boot the deployment's own kernel.
     *
     * @return  Application  The Mezzio pipeline.
     *
     * @since   2.0.0
     */
    private function application(): Application
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $application = $container->get(Application::class);
        self::assertInstanceOf(Application::class, $application);

        return $application;
    }

    /**
     * Send one GET through the pipeline on the configured origin.
     *
     * @param   Application            $application  Pipeline under test.
     * @param   string                 $target       Path and query.
     * @param   array<string, string>  $headers      Request headers.
     *
     * @return  ResponseInterface  The pipeline's answer.
     *
     * @since   2.0.0
     */
    private function handle(Application $application, string $target, array $headers = []): ResponseInterface
    {
        $uri = rtrim(Environment::fromGlobals()->string('APP_BASE_URL'), '/') . $target;
        $request = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $application->handle($request);
    }
}
