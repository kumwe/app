<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use InvalidArgumentException;

/**
 * Bounded outcome of resolving one Blueprint value binding.
 *
 * @since  2.0.0
 */
final readonly class StudioPreviewBindingResult
{
    /**
     * Capture one available, hidden, or unresolved value without interpreting it as markup.
     *
     * @param   bool   $available  Whether a trusted source supplied a value or declared fallback.
     * @param   bool   $hidden     Whether binding policy suppresses the block's visible value.
     * @param   mixed  $value      JSON value when available, null otherwise.
     *
     * @throws  InvalidArgumentException  When contradictory state is supplied.
     *
     * @since   2.0.0
     */
    public function __construct(
        public bool $available,
        public bool $hidden,
        public mixed $value,
    ) {
        if ($hidden && $available) {
            throw new InvalidArgumentException('A hidden Studio preview binding cannot expose a value.');
        }
    }

    /**
     * Create the absence result used when a node has no value binding or resolution fails closed.
     *
     * @return  self  Unavailable non-hidden value.
     *
     * @since   2.0.0
     */
    public static function unavailable(): self
    {
        return new self(false, false, null);
    }

    /**
     * Create the result required by `onNull` or `onError` hide policy.
     *
     * @return  self  Hidden value carrying no source data.
     *
     * @since   2.0.0
     */
    public static function hidden(): self
    {
        return new self(false, true, null);
    }
}
