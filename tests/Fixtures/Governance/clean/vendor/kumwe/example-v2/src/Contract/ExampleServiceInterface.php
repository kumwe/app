<?php

declare(strict_types=1);

namespace Kumwe\Example\Contract;

/**
 * The describing contract of the fictitious example package.
 *
 * @since  2.0.0
 */
interface ExampleServiceInterface
{
    /**
     * Describe a subject.
     *
     * @param   string  $subject  Name of the subject.
     *
     * @return  string  The description; never empty.
     *
     * @since   2.0.0
     */
    public function describe(string $subject): string;
}
