<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Middleware;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Turns away an oversized request before its body reaches a handler.
 *
 * The guard reads only the declared `Content-Length`, so it costs nothing and can sit early in the
 * pipeline: a client that announces more than the configured budget is answered 413 without the body
 * being parsed or buffered. Because the check is on the declaration rather than the wire, a request
 * that sends no `Content-Length` — a chunked upload, or a bodyless GET — passes through; bounding the
 * bytes actually transferred remains the web server's job. This is a cheap first line of defence, not
 * the only one.
 *
 * @since  2.0.0
 */
final readonly class BodyLimitMiddleware implements MiddlewareInterface
{
    /**
     * Fix the largest request body this pipeline is willing to accept.
     *
     * @param  int  $maximumBytes  Inclusive upper bound on the declared `Content-Length`, in bytes.
     *
     * @since  2.0.0
     */
    public function __construct(private int $maximumBytes)
    {
    }

    /**
     * Answer 413 when the declared body size is unusable or over budget, otherwise delegate.
     *
     * A `Content-Length` that is not an integer, or that is negative, is refused exactly like an
     * oversized one, so a client cannot slip past the budget by declaring a malformed value.
     *
     * @param   ServerRequestInterface   $request  Request whose `Content-Length` header is inspected.
     * @param   RequestHandlerInterface  $handler  Next handler, reached when the declared size is acceptable.
     *
     * @return  ResponseInterface  The handler's response, or a 413 `application/problem+json` document.
     *
     * @since   2.0.0
     */
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
