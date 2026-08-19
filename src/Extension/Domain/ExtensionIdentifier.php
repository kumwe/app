<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * Canonical `vendor/name` identity every part of the extension subsystem keys an extension by.
 *
 * Parsing through `fromString` is the only way to obtain one, so an instance is proof that the value
 * has been trimmed, lowercased, and matched against the two-segment grammar. Collaborators lean on
 * that: `ExtensionTableNames` folds the value straight into a physical table prefix without
 * re-checking it, and code holding only a raw string routes it through here before using it as a
 * registry key or a contribution owner, so one grammar governs every spelling of an extension.
 *
 * @since  2.0.0
 */
final readonly class ExtensionIdentifier implements Stringable
{
    /**
     * Wrap a value that has already passed the grammar check.
     *
     * @param  string  $value  Normalised `vendor/name` identifier.
     *
     * @since  2.0.0
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Parse and normalise an identifier written as `vendor/name`.
     *
     * Surrounding whitespace and uppercase input are normalised away, so those are not errors.
     * Everything else is refused rather than repaired: a missing or extra slash, an empty segment, a
     * segment that does not open with a letter or digit, a character outside `[a-z0-9._-]`, or a
     * segment longer than 63 characters.
     *
     * @param   string  $value  Raw identifier from a manifest, a request path, or configuration.
     *
     * @return  self  The normalised identifier.
     *
     * @throws  InvalidArgumentException  When the value does not match the `vendor/name` grammar.
     *
     * @since   2.0.0
     */
    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));

        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,62})\/[a-z0-9](?:[a-z0-9._-]{0,62})$/D', $value) !== 1) {
            throw new InvalidArgumentException('An extension identifier must use the lowercase vendor/name format.');
        }

        return new self($value);
    }

    /**
     * Expose the normalised value for storage, keying, and identifier composition.
     *
     * @return  string  Lowercase `vendor/name`, unchanged by upgrades of the extension it names.
     *
     * @since   2.0.0
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Compare two identifiers by their normalised value.
     *
     * @param   self  $other  Identifier to compare this one against.
     *
     * @return  bool  True when both name the same extension.
     *
     * @since   2.0.0
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Render the identifier where it is interpolated into a message, path, or query parameter.
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
