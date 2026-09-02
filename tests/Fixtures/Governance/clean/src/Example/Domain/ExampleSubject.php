<?php

declare(strict_types=1);

namespace Kumwe\App\Example\Domain;

/**
 * A subject the App describes; a domain value of the governance fixture.
 *
 * @since  2.0.0
 */
final readonly class ExampleSubject
{
    /**
     * Bind the name.
     *
     * @param  string  $name  Subject name.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $name,
    ) {
    }
}
