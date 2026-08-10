<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Middleware;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Turns away an oversized request before its body reaches a handler.
 *
 * A declared `Content-Length` is rejected before materialization, then the stream is copied through a
 * bounded buffer so chunked and undeclared bodies meet the identical limit. The replacement stream is
 * rewound before delegation, preserving the body for every downstream JSON or form parser.
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
     * Answer 413 when the declared or actual body size is unusable or over budget, otherwise delegate.
     *
     * A `Content-Length` that is not an integer, or that is negative, is refused exactly like an
     * oversized one, so a client cannot slip past the budget by declaring a malformed value.
     *
     * @param   ServerRequestInterface   $request  Request whose declared and actual body size is inspected.
     * @param   RequestHandlerInterface  $handler  Next handler, reached when the body is within the limit.
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
            return $this->tooLarge();
        }

        $source = $request->getBody();
        if ($source->isSeekable()) {
            $source->rewind();
        }
        $copy = new Stream('php://temp', 'wb+');
        $bytes = 0;
        while (true) {
            $chunk = $source->read(min(8192, $this->maximumBytes - $bytes + 1));
            if ($chunk === '') {
                if ($source->eof()) {
                    break;
                }

                return $this->tooLarge();
            }
            $bytes += strlen($chunk);
            if ($bytes > $this->maximumBytes) {
                return $this->tooLarge();
            }
            $copy->write($chunk);
            if ($source->eof()) {
                break;
            }
        }
        $copy->rewind();

        return $handler->handle($request->withBody($copy));
    }

    /**
     * Build the stable RFC 9457 response for either size check.
     *
     * @return  ResponseInterface  A 413 `application/problem+json` document.
     *
     * @since   2.0.0
     */
    private function tooLarge(): ResponseInterface
    {
        return new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Content Too Large',
            'status' => 413,
            'detail' => 'The request body exceeds the configured limit.',
        ], 413, ['Content-Type' => 'application/problem+json']);
    }
}
