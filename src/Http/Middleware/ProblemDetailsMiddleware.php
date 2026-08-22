<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Middleware;

use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Application\Security\HighImpactAuthenticationRequired;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Identity\Application\Administration\AuthenticationThrottled;
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
     * @param  LoggerInterface                $logger    Destination for unhandled exceptions and request ids.
     * @param  bool                           $debug     Whether 500 responses may disclose exception messages.
     * @param  ProblemDetailsResponseFactory  $problems  Registry-enforcing RFC 9457 response factory.
     *
     * @since  2.0.0
     */
    public function __construct(
        private LoggerInterface $logger,
        private bool $debug,
        private ProblemDetailsResponseFactory $problems = new ProblemDetailsResponseFactory(),
    ) {
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
                return $this->problems->create(
                    403,
                    'Forbidden',
                    'The authenticated identity is not authorized for this operation.',
                    'urn:kumwe:problem:authorization-denied',
                )->withHeader('Cache-Control', 'no-store');
            }
            if ($exception instanceof HighImpactAuthenticationRequired) {
                return $this->problems->create(
                    403,
                    'Step-up authentication required',
                    'Current-password authentication is required for this high-impact operation.',
                    'urn:kumwe:problem:high-impact-authentication-required',
                )->withHeader('Cache-Control', 'no-store');
            }
            if ($exception instanceof AuthenticationThrottled) {
                return $this->problems->create(
                    429,
                    'Too Many Requests',
                    'Too many authentication attempts. Try again later.',
                    'urn:kumwe:problem:authentication-throttled',
                )->withHeader('Cache-Control', 'no-store');
            }

            $requestAttribute = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE, 'unknown');
            $requestId = is_string($requestAttribute) ? $requestAttribute : 'unknown';
            $this->logger->error('Unhandled request exception.', [
                'exception' => $exception,
                'request_id' => $requestId,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
            ]);

            return $this->problems->create(
                500,
                'Internal Server Error',
                $this->debug ? $exception->getMessage() : 'The request could not be completed.',
                extensions: ['request_id' => $requestId],
            );
        }
    }
}
