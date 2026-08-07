<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Middleware;

use Kumwe\CMS\Http\Security\SecurityHeaders;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private bool $production)
    {
    }

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
