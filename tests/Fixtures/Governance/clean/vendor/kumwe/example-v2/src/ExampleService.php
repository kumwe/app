<?php

declare(strict_types=1);

namespace Kumwe\Example;

use Kumwe\Example\Contract\ExampleServiceInterface;
use Kumwe\Example\Internal\Helper;

/**
 * Default implementation of the describing contract.
 *
 * @since  2.0.0
 */
final readonly class ExampleService implements ExampleServiceInterface
{
    /**
     * Prefix used when none is configured.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string DEFAULT_PREFIX = 'example';

    /**
     * Bind the prefix.
     *
     * @param  string  $prefix  Marker prepended to every description.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $prefix = self::DEFAULT_PREFIX,
    ) {
    }

    /**
     * Describe a subject as `<prefix>: <subject>`.
     *
     * @param   string  $subject  Name of the subject.
     *
     * @return  string  The description.
     *
     * @since   2.0.0
     */
    public function describe(string $subject): string
    {
        return Helper::join($this->prefix, $subject);
    }
}
