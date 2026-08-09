<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Idempotency;

use InvalidArgumentException;
use Stringable;

/**
 * Validated `Idempotency-Key` header value, in the transport-safe form the ledger stores.
 *
 * `RequireIdempotencyKeyMiddleware` parses the header through here once and attaches the result to the
 * request, so everything downstream — the persistent and secret-once idempotency middlewares,
 * `PlanPreviewHandler` — works from the parsed instance rather than the raw header and the character
 * set and length are judged in exactly one place. The private constructor makes `fromHeader()` the only
 * way in, so an instance always satisfies the format rule and its value is safe to persist as a ledger
 * key and to compare without re-validating.
 *
 * The application layer has its own `Automation\IdempotencyKey` for keys that arrive already extracted;
 * this one owns the header spelling and trims surrounding whitespace before judging it.
 *
 * @since  2.0.0
 */
final readonly class IdempotencyKey implements Stringable
{
    /**
     * Wrap a header value that has already passed the format check.
     *
     * @param  string  $value  Trimmed key text as validated by `fromHeader()`.
     *
     * @since  2.0.0
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Validate an `Idempotency-Key` header value and wrap it.
     *
     * Surrounding whitespace is stripped before the check, so a header padded by an intermediary is
     * accepted rather than refused.
     *
     * @param   string  $value  Header line as received, with any surrounding whitespace.
     *
     * @return  self  The trimmed key, guaranteed to match the transport-safe format.
     *
     * @throws  InvalidArgumentException  When the trimmed value is shorter than 8 or longer than 128
     *          characters, does not begin with an ASCII letter or digit, or contains anything beyond
     *          ASCII letters, digits and the `.`, `_`, `:` and `-` separators.
     *
     * @since   2.0.0
     */
    public static function fromHeader(string $value): self
    {
        $value = trim($value);

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                'An idempotency key must contain 8 to 128 transport-safe ASCII characters.',
            );
        }

        return new self($value);
    }

    /**
     * Compare this key with another in constant time.
     *
     * `hash_equals()` removes the early exit a plain comparison would give, so a caller probing for a
     * key it does not hold learns nothing from how long the answer took.
     *
     * @param   self  $other  Key to compare this one against.
     *
     * @return  bool  True when both keys carry the same text.
     *
     * @since   2.0.0
     */
    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    /**
     * Render the key as the text to store in the ledger or send back to the caller.
     *
     * @return  string  The validated value, unchanged since `fromHeader()` trimmed it.
     *
     * @since   2.0.0
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
