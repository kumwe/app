<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Middleware;

use Kumwe\CMS\Http\Security\SecurityHeaders;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stamps the site-wide security headers onto every response leaving the pipeline.
 *
 * Applying the policy at the boundary rather than in each handler means a newly added route cannot
 * ship without it. `SecurityHeaders` owns the policy itself; this middleware decides only the two
 * things that depend on the live request — whether HSTS may be asserted, and whether the response is
 * an SVG. HSTS is withheld outside production and off plain HTTP so a development host is never pinned
 * to TLS, and an `image/svg+xml` response is additionally sandboxed because an SVG is an active
 * document the browser would otherwise run with the site's own origin.
 *
 * @since  2.0.0
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * Record whether this build is allowed to assert HSTS.
     *
     * @param  bool  $production  Whether the application runs in production, where pinning TLS is safe.
     *
     * @since  2.0.0
     */
    public function __construct(private bool $production)
    {
    }

    /**
     * Delegate to the pipeline, then apply the security headers to whatever it returned.
     *
     * Headers are set rather than appended, so a handler cannot weaken the policy by emitting its own
     * value first. The SVG lock-down runs last and deliberately replaces the site policy with
     * `default-src 'none'` plus `sandbox`, which is stricter than the policy it overwrites.
     *
     * @param   ServerRequestInterface   $request  Request whose scheme decides whether HSTS is asserted.
     * @param   RequestHandlerInterface  $handler  Rest of the pipeline, which produces the response.
     *
     * @return  ResponseInterface  The handler's response with the security headers applied.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $secure = $request->getUri()->getScheme() === 'https';

        foreach ((new SecurityHeaders($this->production && $secure))->values() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        if (str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'image/svg+xml')) {
            $response = $response
                ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
                ->withHeader('Cross-Origin-Resource-Policy', 'same-origin');
        }

        return $response;
    }
}
