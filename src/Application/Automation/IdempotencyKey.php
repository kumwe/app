<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use InvalidArgumentException;
use Stringable;

/**
 * Validated caller-supplied token that names one replay-protected operation.
 *
 * Application commands such as `CreateRecordCommand` carry this instead of a raw string, so the
 * character set and length are checked once at the edge of the application layer and every store
 * that keys on it — `IdempotencyRecord`, the business-record idempotency repository — receives a
 * value it can persist and compare without re-validating. The private constructor makes
 * `fromString()` the only way in, so an instance always satisfies the format rule.
 *
 * The delivery layer has its own `Idempotency\IdempotencyKey` for parsing the HTTP header; this one
 * accepts the extracted value verbatim and does not trim surrounding whitespace.
 *
 * @since  2.0.0
 */
final readonly class IdempotencyKey implements Stringable
{
    /**
     * Wrap an already-validated key value.
     *
     * @param  string  $value  Key text that has passed the format check in `fromString()`.
     *
     * @since  2.0.0
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Validate a caller-supplied key and wrap it.
     *
     * @param   string  $value  Key text as extracted by the caller; surrounding whitespace is not stripped
     *          and makes the value fail the check.
     *
     * @return  self  Key guaranteed to be 8 to 128 transport-safe ASCII characters.
     *
     * @throws  InvalidArgumentException  When the value is shorter than 8 or longer than 128 characters,
     *          does not begin with a letter or digit, or contains anything outside the alphanumeric, dot,
     *          underscore, colon and hyphen set.
     *
     * @since   2.0.0
     */
    public static function fromString(string $value): self
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                'An idempotency key must contain 8 to 128 transport-safe ASCII characters.',
            );
        }

        return new self($value);
    }

    /**
     * Return the key text for persistence or for building a storage scope digest.
     *
     * @return  string  The validated key, unchanged from what the caller supplied.
     *
     * @since   2.0.0
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Compare two keys in time independent of how many leading characters agree.
     *
     * The constant-time comparison keeps a caller from probing stored keys one character at a time.
     *
     * @param   self  $other  Key to compare this one against.
     *
     * @return  bool  True when both keys hold the same text.
     *
     * @since   2.0.0
     */
    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    /**
     * Render the key where a plain string is expected, such as a log field or a digest input.
     *
     * @return  string  The validated key text.
     *
     * @since   2.0.0
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
