<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'kumwe.request_id';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $candidate = trim($request->getHeaderLine('X-Request-ID'));
        $requestId = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $candidate) === 1
            ? $candidate
            : bin2hex(random_bytes(16));

        $response = $handler->handle($request->withAttribute(self::ATTRIBUTE, $requestId));

        return $response->withHeader('X-Request-ID', $requestId);
    }
}
