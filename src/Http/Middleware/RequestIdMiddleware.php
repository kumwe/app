<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gives every request a correlation identifier and echoes it back on the response.
 *
 * It is the outermost middleware in the pipeline, so the identifier exists before anything can fail:
 * the error boundary logs it, `ExecutionContext` carries it into the domain, and the client receives
 * it in `X-Request-ID` to quote in a support report. A caller may supply the identifier so a trace can
 * be stitched across services, but only when it matches a conservative pattern; anything else is
 * replaced with fresh random hex, which keeps an untrusted client from steering log content or header
 * syntax through the value.
 *
 * @since  2.0.0
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    /**
     * Request attribute the identifier is published under for the rest of the pipeline to read.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ATTRIBUTE = 'kumwe.request_id';

    /**
     * Resolve the request identifier, publish it as an attribute, and stamp it on the response.
     *
     * An inbound `X-Request-ID` is honoured only when it trims to 8 to 64 characters drawn from
     * `[A-Za-z0-9._-]`; otherwise a 32-character random value is generated. The response always
     * carries the identifier that was actually used, never a rejected candidate.
     *
     * @param   ServerRequestInterface   $request  Request whose `X-Request-ID` header is offered as the id.
     * @param   RequestHandlerInterface  $handler  Next handler, called with the identifier attribute already set.
     *
     * @return  ResponseInterface  The handler's response with `X-Request-ID` set to the resolved identifier.
     *
     * @since   2.0.0
     */
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
