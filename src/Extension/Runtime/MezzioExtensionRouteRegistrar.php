<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Mezzio\Application;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class MezzioExtensionRouteRegistrar implements ExtensionRouteRegistrar
{
    private string $extension;

    public function __construct(
        private Application $application,
        string $extension,
        private TrustStore $trust,
    )
    {
        $this->extension = ExtensionIdentifier::fromString($extension)->value();
    }

    public function route(
        string $path,
        RequestHandlerInterface $handler,
        array $methods,
        string $name,
    ): void {
        $pathPrefix = '/extensions/' . $this->extension;
        $namePrefix = 'extension.' . str_replace('/', '.', $this->extension) . '.';
        if (!str_starts_with($path, $pathPrefix . '/') || !str_starts_with($name, $namePrefix)) {
            throw new InvalidArgumentException('Extension routes must remain inside their path and name namespace.');
        }
        if ($methods === [] || !array_is_list($methods)) {
            throw new InvalidArgumentException('Extension route methods must be a non-empty list.');
        }
        foreach ($methods as $method) {
            if (!is_string($method) || preg_match('/^[A-Z]+$/D', $method) !== 1) {
                throw new InvalidArgumentException('Extension route methods must be uppercase HTTP methods.');
            }
        }

        $this->application->route(
            $path,
            new TrustEnforcingRequestHandler($handler, $this->trust, $this->extension),
            $methods,
            $name,
        );
    }
}
