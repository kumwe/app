<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Psr\Http\Server\RequestHandlerInterface;

interface ExtensionRouteRegistrar
{
    /** @param non-empty-list<string> $methods */
    public function route(
        string $path,
        RequestHandlerInterface $handler,
        array $methods,
        string $name,
    ): void;
}
