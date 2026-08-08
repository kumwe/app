<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Middleware;

use Kumwe\CMS\Application\Authorization\AuthorizationDenied;
use Kumwe\CMS\Application\Security\HighImpactAuthenticationRequired;
use Kumwe\CMS\Identity\Application\Administration\AuthenticationThrottled;
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
            if ($exception instanceof AuthorizationDenied) {
                return new JsonResponse([
                    'type' => 'urn:kumwe:problem:authorization-denied',
                    'title' => 'Forbidden',
                    'status' => 403,
                    'detail' => 'The authenticated identity is not authorized for this operation.',
                ], 403, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
            }
            if ($exception instanceof HighImpactAuthenticationRequired) {
                return new JsonResponse([
                    'type' => 'urn:kumwe:problem:high-impact-authentication-required',
                    'title' => 'Step-up authentication required',
                    'status' => 403,
                    'detail' => 'Current-password authentication is required for this high-impact operation.',
                ], 403, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
            }
            if ($exception instanceof AuthenticationThrottled) {
                return new JsonResponse([
                    'type' => 'urn:kumwe:problem:authentication-throttled',
                    'title' => 'Too Many Requests',
                    'status' => 429,
                    'detail' => 'Too many authentication attempts. Try again later.',
                ], 429, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']);
            }

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
