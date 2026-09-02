<?php

declare(strict_types=1);

namespace Kumwe\App\Kernel;

use Kumwe\App\Example\Infrastructure\PrefixedExampleService;
use Kumwe\Example\Contract\ExampleServiceInterface;

/**
 * Composition root of the governance fixture: binds the package interface to the App adapter.
 *
 * @since  2.0.0
 */
final readonly class ContainerFactory
{
    /**
     * The host bindings, keyed by service identifier.
     *
     * @return  array<class-string, class-string>  Service identifier to implementation.
     *
     * @since   2.0.0
     */
    public function factories(): array
    {
        return [
            ExampleServiceInterface::class => PrefixedExampleService::class,
        ];
    }
}
