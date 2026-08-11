<?php

declare(strict_types=1);

namespace Kumwe\CMS\InterfaceStandard;

use InvalidArgumentException;
use Stringable;

/**
 * Semantic resource or element name used by an interface declaration.
 *
 * The name describes business meaning only. It cannot be a route, template path, selector, expression,
 * statement, or executable fragment.
 *
 * @since  2.0.0
 */
final readonly class ResourceName implements Stringable
{
    /**
     * Hold a semantic name already validated by `fromString()`.
     *
     * @param  string  $value  Canonical lowercase delimiter-separated name.
     *
     * @since  2.0.0
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Validate a bounded semantic name for a resource or responsive element.
     *
     * @param   string  $value  Lowercase words separated by single dots or hyphens.
     *
     * @return  self  Safe semantic name.
     *
     * @throws  InvalidArgumentException  When the value is not a bounded semantic identifier.
     *
     * @since   2.0.0
     */
    public static function fromString(string $value): self
    {
        if (
            $value === ''
            || strlen($value) > 191
            || preg_match('/^[a-z][a-z0-9]*(?:(?:\.|-)[a-z0-9]+)*$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('A KIS resource name must be a lowercase semantic identifier.');
        }

        return new self($value);
    }

    /**
     * Return the semantic name for comparison or serialization.
     *
     * @return  string  Canonical lowercase name.
     *
     * @since   2.0.0
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Render the canonical resource name.
     *
     * @return  string  Same value returned by `value()`.
     *
     * @since   2.0.0
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
