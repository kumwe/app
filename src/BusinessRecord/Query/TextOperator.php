<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

/**
 * Where in a stored value a `TextFilter`'s search text has to appear.
 *
 * These three anchorings are the whole vocabulary a text filter offers, and each one decides only where
 * the compiler places the `%` wildcards around the caller's text — the text itself is escaped and
 * lowercased first, so no case has any way to widen the pattern further. There is no regular-expression
 * or word-boundary case, because the match has to mean the same thing on every supported engine.
 *
 * @since  2.0.0
 */
enum TextOperator: string
{
    /**
     * The text appears anywhere inside the stored value.
     *
     * The wildcard on the leading side rules out answering the match by seeking a prefix range, so this is
     * the case to think twice about on a large table.
     *
     * @since  2.0.0
     */
    case Contains = 'contains';

    /**
     * The stored value begins with the text.
     *
     * @since  2.0.0
     */
    case StartsWith = 'starts_with';

    /**
     * The stored value ends with the text.
     *
     * @since  2.0.0
     */
    case EndsWith = 'ends_with';
}
