<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Handler;

use Kumwe\CMS\Infrastructure\Persistence\ReadinessProbe;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ReadinessHandler implements RequestHandlerInterface
{
    public function __construct(private ReadinessProbe $probe)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ready = $this->probe->ready();

        return new JsonResponse(
            ['status' => $ready ? 'ready' : 'not_ready'],
            $ready ? 200 : 503,
        );
    }
}
