<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the discovery document that sits at the root of the versioned HTTP API.
 *
 * A client that holds nothing but the deployment's base URL starts here: the document names the product
 * and the API version this installation speaks, so tooling can confirm it is talking to Kumwe, and to a
 * version it understands, before it calls a resource route. The answer is a constant — no storage is
 * read and no credential is required — which also makes `/api/v1` the cheapest confirmation that the
 * routing table and the middleware pipeline in front of the API are intact.
 *
 * @since  2.0.0
 */
final class ApiIndexHandler implements RequestHandlerInterface
{
    /**
     * Answers a request for the API root with the static discovery document.
     *
     * @param   ServerRequestInterface  $request  Incoming request; nothing it carries changes the answer.
     *
     * @return  ResponseInterface  A 200 JSON body carrying `product`, `api_version`, and `status`.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse([
            'product' => 'Kumwe CMS',
            'api_version' => 'v1',
            'status' => 'available',
        ]);
    }
}
