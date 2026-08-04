<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Middleware;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ProblemDetailsMiddleware implements MiddlewareInterface
{
    public function __construct(private LoggerInterface $logger, private bool $debug)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $exception) {
            $requestAttribute = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE, 'unknown');
            $requestId = is_string($requestAttribute) ? $requestAttribute : 'unknown';
            $this->logger->error('Unhandled request exception.', [
                'exception' => $exception,
                'request_id' => $requestId,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
            ]);

            $problem = [
                'type' => 'about:blank',
                'title' => 'Internal Server Error',
                'status' => 500,
                'detail' => $this->debug ? $exception->getMessage() : 'The request could not be completed.',
                'request_id' => $requestId,
            ];

            return new JsonResponse($problem, 500, ['Content-Type' => 'application/problem+json']);
        }
    }
}
