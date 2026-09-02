<?php

declare(strict_types=1);

namespace Kumwe\Example;

use Kumwe\Example\Container\ExampleServiceFactory;
use Kumwe\Example\Contract\ExampleServiceInterface;

/**
 * Laminas configuration provider of the fictitious example package.
 *
 * @since  2.0.0
 */
final class ConfigProvider
{
    /**
     * Declare the package's own factory, alias and safe defaults.
     *
     * @return  array<string, array<string, array<string, string>>>  Dependencies and `kumwe.example` defaults.
     *
     * @since   2.0.0
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'factories' => [ExampleService::class => ExampleServiceFactory::class],
                'aliases' => [ExampleServiceInterface::class => ExampleService::class],
            ],
            'kumwe' => [
                'example' => ['prefix' => ExampleService::DEFAULT_PREFIX],
            ],
        ];
    }
}
