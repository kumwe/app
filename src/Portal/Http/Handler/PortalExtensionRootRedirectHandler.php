<?php

declare(strict_types=1);

namespace Kumwe\CMS\Portal\Http\Handler;

use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Canonicalizes the derived trailing-slash alias for an extension portal root.
 *
 * A portal contribution declares only one root route, mounted without a trailing slash. The route
 * registry derives a capability- and trust-protected slash alias from that declaration so a request
 * cannot fall through to the public-site catch-all. This handler completes that normalization without
 * rendering or executing extension code and preserves the query string across the permanent redirect.
 *
 * @since  2.0.0
 */
final readonly class PortalExtensionRootRedirectHandler implements RequestHandlerInterface
{
    /**
     * Bind the redirect to the canonical extension portal root.
     *
     * @param  string  $canonicalPath  Absolute mounted extension path without a trailing slash.
     *
     * @since  2.0.0
     */
    public function __construct(private string $canonicalPath)
    {
    }

    /**
     * Redirect the slash alias to the canonical root while retaining request query parameters.
     *
     * @param   ServerRequestInterface  $request  Alias request whose query string is retained.
     *
     * @return  ResponseInterface  Permanent, method-preserving redirect to the canonical route.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getUri()->getQuery();

        return new RedirectResponse(
            $this->canonicalPath . ($query === '' ? '' : '?' . $query),
            308,
            ['Cache-Control' => 'no-store'],
        );
    }
}
