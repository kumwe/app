<?php

declare(strict_types=1);

namespace Kumwe\App\Example\Application;

use Kumwe\App\Example\Domain\ExampleSubject;
use Kumwe\Example\Contract\ExampleServiceInterface;

/**
 * Application service that composes the package contract with an App subject.
 *
 * @since  2.0.0
 */
final readonly class DescribeSubject
{
    /**
     * Bind the package contract.
     *
     * @param  ExampleServiceInterface  $service  Describing service supplied by the host.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExampleServiceInterface $service,
    ) {
    }

    /**
     * Describe a subject through the package contract.
     *
     * @param   ExampleSubject  $subject  The subject.
     *
     * @return  string  The description.
     *
     * @since   2.0.0
     */
    public function describe(ExampleSubject $subject): string
    {
        return $this->service->describe($subject->name);
    }
}
