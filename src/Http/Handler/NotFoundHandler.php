<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Terminates the middleware pipeline with a problem document when nothing else claimed the request.
 *
 * Mezzio pipes this last, so it only ever runs for a request that no route matched and no middleware
 * answered. It replies in `application/problem+json` rather than HTML so that an API client — the caller
 * most likely to fall through the pipeline — gets a machine-readable refusal it can branch on. The body
 * is a fixed document that never echoes the requested target, which keeps an unmatched path from
 * confirming anything about the routing table. Public site pages are not served from here: a miss under
 * the site routes reaches `PublishedContentHandler`, which renders an HTML page instead.
 *
 * @since  2.0.0
 */
final class NotFoundHandler implements RequestHandlerInterface
{
    /**
     * Produces the pipeline's terminal 404 for a request that reached the end unhandled.
     *
     * @param   ServerRequestInterface  $request  Request no route or middleware claimed; not inspected.
     *
     * @return  ResponseInterface  A 404 problem document carrying `type`, `title`, `status`, and a
     *          constant `detail` that names no path.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => 'The requested resource was not found.',
        ], 404, ['Content-Type' => 'application/problem+json']);
    }
}
