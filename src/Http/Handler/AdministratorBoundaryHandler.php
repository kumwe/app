<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AdministratorBoundaryHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Forbidden',
            'status' => 403,
            'detail' => 'Administrator identity is required.',
        ], 403, ['Content-Type' => 'application/problem+json']);
    }
}
