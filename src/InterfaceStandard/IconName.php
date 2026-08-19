<?php

declare(strict_types=1);

namespace Kumwe\App\InterfaceStandard;

use InvalidArgumentException;
use Stringable;

/**
 * Safe semantic icon reference resolved by the active theme's validated icon registry.
 *
 * It carries a name only, never SVG, markup, a URL, or an asset path. Core and extension templates can
 * therefore provide their own visual implementation without changing a surface declaration.
 *
 * @since  2.0.0
 */
final readonly class IconName implements Stringable
{
    /**
     * Hold an icon name already checked by `fromString()`.
     *
     * @param  string  $value  Canonical theme registry key.
     *
     * @since  2.0.0
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Validate a theme-neutral icon registry key.
     *
     * @param   string  $value  Lowercase icon name without paths or markup.
     *
     * @return  self  Validated icon reference.
     *
     * @throws  InvalidArgumentException  When the name cannot be resolved safely through an icon registry.
     *
     * @since   2.0.0
     */
    public static function fromString(string $value): self
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('A KIS icon must be a lowercase semantic registry name.');
        }

        return new self($value);
    }

    /**
     * Return the theme registry key.
     *
     * @return  string  Canonical icon name.
     *
     * @since   2.0.0
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Render the icon registry key.
     *
     * @return  string  Same canonical value returned by `value()`.
     *
     * @since   2.0.0
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
