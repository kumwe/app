<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use InvalidArgumentException;
use Stringable;

/**
 * Opaque browse token as it crosses the process boundary, bounded and shape-checked at the edge.
 *
 * A page cursor travels out to a client and comes back on the next request, so the string is untrusted
 * on arrival. This type owns only what can be decided without the signing key: that the token is two
 * base64url segments joined by a single dot and stays inside a size a request may reasonably spend
 * hashing. That is what lets `RecordCursorCodec` split it without guarding for a missing half, and what
 * keeps an unbounded string from reaching the HMAC at all. Everything the token means — its signature
 * and the `CursorPosition` inside — belongs to the codec, so holding one of these says nothing about
 * whether it was minted here.
 *
 * @since  2.0.0
 */
final readonly class RecordCursor implements Stringable
{
    /**
     * Largest token accepted, bounding the work one request can be made to spend hashing a cursor.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_BYTES = 65_536;

    /**
     * Wrap a token that has already passed the shape and size check.
     *
     * @param  string  $token  Validated token in `payload.signature` form; `fromString()` is the only
     *         way to reach this constructor.
     *
     * @since  2.0.0
     */
    private function __construct(private string $token)
    {
    }

    /**
     * Accept a token from the outside world once its shape and size are known to be safe.
     *
     * @param   string  $token  Cursor exactly as the caller sent it back, before any signature check.
     *
     * @return  self  The token wrapped so the codec can verify it.
     *
     * @throws  InvalidArgumentException  When the token is under 32 bytes or over 65536, or is not two
     *          non-empty base64url segments separated by a single dot.
     *
     * @since   2.0.0
     */
    public static function fromString(string $token): self
    {
        if (
            strlen($token) < 32 || strlen($token) > self::MAX_BYTES
            || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token) !== 1
        ) {
            throw new InvalidArgumentException('A business-record cursor is malformed or unbounded.');
        }

        return new self($token);
    }

    /**
     * Read the raw token back out, for signature verification or for writing into a response.
     *
     * @return  string  The token in `payload.signature` form, byte for byte as it was accepted.
     *
     * @since   2.0.0
     */
    public function value(): string
    {
        return $this->token;
    }

    /**
     * Render the cursor wherever a string is expected, such as a query parameter or a JSON body.
     *
     * @return  string  The same token `value()` returns.
     *
     * @since   2.0.0
     */
    public function __toString(): string
    {
        return $this->token;
    }
}
