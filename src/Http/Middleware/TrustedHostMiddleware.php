<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Middleware;

use InvalidArgumentException;
use Kumwe\CMS\Http\Security\TrustedHostMatcher;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Refuses a request whose `Host` header is not one this site has claimed.
 *
 * Absolute URLs, password-reset links and cache keys are all derived from the host a client sent, so
 * an unchecked `Host` header lets an attacker poison them. This middleware runs immediately after
 * `TrustedProxyMiddleware`, once forwarding metadata has already been resolved into the effective
 * host, and delegates the decision to `TrustedHostMatcher`. A malformed host and an untrusted one get
 * the identical 400 answer, so the response gives away nothing about which patterns are configured.
 *
 * @since  2.0.0
 */
final readonly class TrustedHostMiddleware implements MiddlewareInterface
{
    /**
     * Wire the matcher holding the host patterns this site answers for.
     *
     * @param  TrustedHostMatcher  $matcher  Matcher built from the configured trusted-host patterns.
     *
     * @since  2.0.0
     */
    public function __construct(private TrustedHostMatcher $matcher)
    {
    }

    /**
     * Delegate the request when its `Host` header is trusted, and answer 400 when it is not.
     *
     * `InvalidArgumentException` from the matcher is caught rather than allowed to propagate: a
     * malformed host is simply an untrusted host, and answering it any differently would give a caller
     * a way to distinguish rejection reasons.
     *
     * @param   ServerRequestInterface   $request  Request whose `Host` header is checked.
     * @param   RequestHandlerInterface  $handler  Next handler, reached only for a trusted host.
     *
     * @return  ResponseInterface  The handler's response, or a 400 `application/problem+json` document.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            if ($this->matcher->matches($request->getHeaderLine('Host'))) {
                return $handler->handle($request);
            }
        } catch (InvalidArgumentException) {
            // Malformed and untrusted hosts have the same externally visible result.
        }

        return new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Bad Request',
            'status' => 400,
            'detail' => 'The request host is not accepted.',
        ], 400, ['Content-Type' => 'application/problem+json']);
    }
}
