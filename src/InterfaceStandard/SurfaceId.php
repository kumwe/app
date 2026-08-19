<?php

declare(strict_types=1);

namespace Kumwe\App\InterfaceStandard;

use InvalidArgumentException;
use Stringable;

/**
 * Stable owner-namespaced identifier of one semantic interface surface.
 *
 * @since  2.0.0
 */
final readonly class SurfaceId implements Stringable
{
    /**
     * Longest identifier accepted by the contribution inventory and manifest contract.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_LENGTH = 191;

    /**
     * Hold a surface identifier already checked by `fromString()`.
     *
     * @param  string  $value  Canonical lowercase dotted identifier.
     *
     * @since  2.0.0
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Validate an owner-namespaced semantic surface identifier.
     *
     * The value starts and ends with a lowercase letter or digit, contains at least one dot, and may
     * otherwise contain lowercase letters, digits, dots, underscores, or hyphens. Internal repeated
     * dots remain representable because the canonical extension grammar has historically admitted
     * them inside `vendor/name` segments. The owner boundary is enforced separately by
     * `ContributionOwner::assertOwns()`, so lexical compatibility does not let a contribution claim
     * another owner's namespace.
     *
     * @param   string  $value  Identifier exactly as declared by core or an extension manifest.
     *
     * @return  self  Validated identifier safe for contribution inventory keys.
     *
     * @throws  InvalidArgumentException  When the value is empty, too long, or outside the identifier grammar.
     *
     * @since   2.0.0
     */
    public static function fromString(string $value): self
    {
        if (
            $value === ''
            || strlen($value) > self::MAX_LENGTH
            || preg_match('/^[a-z0-9][a-z0-9._-]*\.[a-z0-9._-]*[a-z0-9]$/D', $value)
                !== 1
        ) {
            throw new InvalidArgumentException('A KIS surface identifier must be a lowercase dotted name.');
        }

        return new self($value);
    }

    /**
     * Return the canonical inventory key.
     *
     * @return  string  Lowercase dotted identifier.
     *
     * @since   2.0.0
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Render the identifier for deterministic manifests and diagnostics.
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
