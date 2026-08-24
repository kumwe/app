<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Contract;

use RuntimeException;

/**
 * A value the canonical JSON form refuses to represent.
 *
 * The portability contract's canonicalization never truncates, never drops a member and never
 * guesses: nesting past the declared bound, a forbidden member name, or a value that is not
 * JSON-representable rejects the whole document with a stable reason the corpus vectors compare.
 *
 * @since  2.0.0
 */
final class CanonicalJsonRejected extends RuntimeException
{
    /**
     * Name the stable reason a value was refused.
     *
     * @param  string  $reason   Portable reason: `depth-exceeded`, `forbidden-member` or `not-json`.
     * @param  string  $message  Human-readable explanation; not a conformance value.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
