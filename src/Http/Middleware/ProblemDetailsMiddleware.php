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

/**
 * Converts anything thrown by the pipeline into a problem document, so no failure escapes as a stack trace.
 *
 * This is the error boundary of the HTTP stack, piped directly inside `RequestIdMiddleware` so that
 * every failure it reports carries the same request id the client is handed back. Three application
 * failures are given a `urn:kumwe:problem:` type and a status of their own — authorization denial,
 * step-up authentication and authentication throttling — because a client can act on each of them.
 * Everything else is treated as a defect: it is logged with the exception and request id, and answered
 * as an opaque 500 whose detail names the exception only when the application runs in debug mode, so a
 * production response never discloses internals.
 *
 * @since  2.0.0
 */
final readonly class ProblemDetailsMiddleware implements MiddlewareInterface
{
    /**
     * Wire the sink for unexpected failures and decide how much detail responses may carry.
     *
     * @param  LoggerInterface  $logger  Destination for unhandled exceptions, recorded with the request id.
     * @param  bool             $debug   Whether the 500 response may disclose the exception message.
     *
     * @since  2.0.0
     */
    public function __construct(private LoggerInterface $logger, private bool $debug)
    {
    }

    /**
     * Run the rest of the pipeline and translate anything it throws into a problem response.
     *
     * The catch is deliberately broad because this is the boundary that must not let a `Throwable`
     * reach the emitter. Recognised application failures are mapped to their own status and are not
     * logged as errors, since they are expected outcomes; every other exception is logged at error
     * level and answered 500 with the request id, which is the handle an operator uses to find the log
     * line from a client's report.
     *
     * @param   ServerRequestInterface   $request  Request passed through unchanged to the next handler.
     * @param   RequestHandlerInterface  $handler  Rest of the pipeline, whose failures this method absorbs.
     *
     * @return  ResponseInterface  The handler's response, or an `application/problem+json` document.
     *
     * @since   2.0.0
     */
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
