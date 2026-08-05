<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Fails closed when an installed route's extension is disabled or loses trust. */
final readonly class TrustEnforcingRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestHandlerInterface $inner,
        private TrustStore $trust,
        private string $extension,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->trust->synchronizedLifecycle(function () use ($request): ResponseInterface {
            $this->trust->enforceRuntimeTrust($this->extension);
            return $this->inner->handle($request);
        });
    }
}
