<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * Name of one thing an actor may be permitted to do, normalised at the point it enters the domain.
 *
 * Capabilities are the vocabulary grants are written in: `CapabilityGrant` pairs one with a scope,
 * `PrincipalGrant` carries one for an authenticated token, and extension manifests declare their own
 * through `CapabilityDefinition`. Wrapping the string in a type is what stops `Content.Publish` and
 * ` content.publish ` from becoming two different permissions, since every value passes through
 * `fromString()` — the only constructor — which trims, lowercases and enforces the identifier grammar
 * before anything is compared or stored.
 *
 * @since  2.0.0
 */
final readonly class Capability implements Stringable
{
    /**
     * Longest capability accepted, matching the width of the stored capability code columns.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_LENGTH = 191;

    /**
     * Wrap a value that `fromString()` has already normalised and checked.
     *
     * @param  string  $value  Lowercase, delimiter-separated capability code.
     *
     * @since  2.0.0
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Normalise and validate a capability code from an operator, a manifest, or a stored row.
     *
     * Trimming and lowercasing happen before the value is judged, so surrounding whitespace and casing
     * are corrected rather than refused. The grammar itself is strict: a leading letter, then
     * alphanumeric groups joined by single `.`, `_`, `:` or `-` separators, with no trailing separator.
     *
     * @param   string  $value  Capability code as written, in any casing and with any surrounding space.
     *
     * @return  self  The normalised capability.
     *
     * @throws  InvalidArgumentException  When the trimmed value is empty, longer than 191 characters,
     *          or not a lowercase delimiter-separated identifier.
     *
     * @since   2.0.0
     */
    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));

        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A capability must contain between 1 and 191 characters.');
        }

        if (preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+)*$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                'A capability must be a lowercase, delimiter-separated identifier.',
            );
        }

        return new self($value);
    }

    /**
     * The normalised code, for writing to a row or matching against one already there.
     *
     * @return  string  Lowercase and delimiter-separated, safe to persist exactly as returned.
     *
     * @since   2.0.0
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Whether another capability names the same permission.
     *
     * Both sides were normalised on construction, so this is a plain identity test rather than a
     * lenient comparison; nothing further is folded here.
     *
     * @param   self  $other  Capability to compare against.
     *
     * @return  bool  True when the two codes are identical.
     *
     * @since   2.0.0
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Render the capability as its code, so it can be interpolated into messages and log lines.
     *
     * @return  string  The same value `value()` returns.
     *
     * @since   2.0.0
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
