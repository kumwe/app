<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Extension;

use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Holds the cross-process lifecycle lock through downstream idempotency commit or rollback. */
final readonly class TrustLifecycleMiddleware implements MiddlewareInterface
{
    public function __construct(private TrustStore $trust)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->trust->synchronizedLifecycle(
            static fn (): ResponseInterface => $handler->handle($request),
        );
    }
}
