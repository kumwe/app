<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Handler;

use Kumwe\App\Presentation\ContentPageRenderService;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the site's validated palette as the same-origin stylesheet every public page links.
 *
 * The palette is a handful of `--site-*` custom properties an operator chooses in site settings, and a
 * menu may bind a different colour scheme to its pages. Both used to reach the browser as a `style`
 * attribute on `<body>`, which was the sole reason the content-security policy still admitted
 * `style-src-attr 'unsafe-inline'`. This route serves the identical declarations as a stylesheet, so
 * the policy can refuse every inline style source while the theme keeps working. The text is derived
 * from the live settings snapshot on every request, is validated as CSS before it leaves, and is
 * versioned by its own digest so a page's link can be cached for a year without ever going stale.
 *
 * @since  2.0.0
 */
final readonly class SitePresentationStylesheetHandler implements RequestHandlerInterface
{
    /**
     * Bind the route to the render service that derives the palette from the settings snapshot.
     *
     * @param  ContentPageRenderService  $pages  Owner of the theme stylesheet text and its validation.
     *
     * @since  2.0.0
     */
    public function __construct(private ContentPageRenderService $pages)
    {
    }

    /**
     * Answer the theme stylesheet for the requested scheme, or a 304 when the browser holds it already.
     *
     * The query admits exactly two parameters: `scheme`, a well-formed scheme handle whose absence
     * means the site default, and `v`, the digest the linking page computed. A `v` that matches the
     * served text earns a long immutable cache lifetime, because a palette change rewrites every
     * page's link; any other request is served with `no-cache` and its `ETag`. Anything else in the
     * query, or a malformed value, is refused as not found rather than interpreted.
     *
     * @param   ServerRequestInterface  $request  Stylesheet request from a public page's `<link>`.
     *
     * @return  ResponseInterface  `text/css` bytes, a 304 revalidation, or a `no-store` 404.
     *
     * @throws  \InvalidArgumentException  When the stored palette carries a value outside the validated
     *          grammar, which the settings store rules out for every value it accepts.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $scheme = $query['scheme'] ?? null;
        $version = $query['v'] ?? null;
        if (
            array_diff(array_map('strval', array_keys($query)), ['scheme', 'v']) !== []
            || ($scheme !== null && (!is_string($scheme) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $scheme) !== 1))
            || ($version !== null && (!is_string($version) || preg_match('/^[a-f0-9]{64}$/D', $version) !== 1))
        ) {
            return new EmptyResponse(404, ['Cache-Control' => 'private, no-store']);
        }
        $stylesheet = $this->pages->themeStylesheet($scheme);
        $digest = hash('sha256', $stylesheet);
        $headers = [
            'Cache-Control' => $version !== null && hash_equals($digest, $version)
                ? 'public, max-age=31536000, immutable'
                : 'public, no-cache, must-revalidate',
            'Content-Type' => 'text/css; charset=utf-8',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'ETag' => '"' . $digest . '"',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if (hash_equals('"' . $digest . '"', $request->getHeaderLine('If-None-Match'))) {
            return new EmptyResponse(304, $headers);
        }

        return new TextResponse($stylesheet, 200, $headers);
    }
}
