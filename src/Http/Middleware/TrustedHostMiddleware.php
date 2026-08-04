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

final readonly class TrustedHostMiddleware implements MiddlewareInterface
{
    public function __construct(private TrustedHostMatcher $matcher)
    {
    }

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
