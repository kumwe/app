<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Middleware;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class BodyLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private int $maximumBytes)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $length = $request->getHeaderLine('Content-Length');

        if (
            $length !== ''
            && (
                filter_var($length, FILTER_VALIDATE_INT) === false
                || (int) $length < 0
                || (int) $length > $this->maximumBytes
            )
        ) {
            return new JsonResponse([
                'type' => 'about:blank',
                'title' => 'Content Too Large',
                'status' => 413,
                'detail' => 'The request body exceeds the configured limit.',
            ], 413, ['Content-Type' => 'application/problem+json']);
        }

        return $handler->handle($request);
    }
}
