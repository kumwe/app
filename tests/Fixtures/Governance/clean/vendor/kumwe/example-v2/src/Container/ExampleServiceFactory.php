<?php

declare(strict_types=1);

namespace Kumwe\Example\Container;

use Kumwe\Example\ExampleService;
use Psr\Container\ContainerInterface;

/**
 * Builds the example service from the `kumwe.example.prefix` option.
 *
 * @since  2.0.0
 */
final readonly class ExampleServiceFactory
{
    /**
     * Construct the shared service.
     *
     * @param   ContainerInterface  $container  Host container carrying the merged configuration.
     *
     * @return  ExampleService  The service with the configured prefix.
     *
     * @since   2.0.0
     */
    public function __invoke(ContainerInterface $container): ExampleService
    {
        /** @var array<string, array<string, array<string, string>>> $config */
        $config = $container->get('config');

        return new ExampleService($config['kumwe']['example']['prefix'] ?? ExampleService::DEFAULT_PREFIX);
    }
}
