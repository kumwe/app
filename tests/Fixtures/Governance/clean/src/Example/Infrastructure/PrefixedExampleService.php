<?php

declare(strict_types=1);

namespace Kumwe\App\Example\Infrastructure;

use Kumwe\Example\Contract\ExampleServiceInterface;

/**
 * Infrastructure adapter implementing the package contract with a host-configured prefix.
 *
 * @since  2.0.0
 */
final readonly class PrefixedExampleService implements ExampleServiceInterface
{
    /**
     * Bind the host prefix.
     *
     * @param  string  $prefix  Site-configured marker.
     *
     * @since  2.0.0
     */
    public function __construct(
        private string $prefix,
    ) {
    }

    /**
     * Describe a subject with the host prefix.
     *
     * @param   string  $subject  Name of the subject.
     *
     * @return  string  `<prefix>: <subject>`.
     *
     * @since   2.0.0
     */
    public function describe(string $subject): string
    {
        return $this->prefix . ': ' . $subject;
    }
}
