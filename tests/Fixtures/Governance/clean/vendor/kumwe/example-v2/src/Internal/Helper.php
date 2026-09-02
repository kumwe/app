<?php

declare(strict_types=1);

namespace Kumwe\Example\Internal;

/**
 * Joins a prefix and a subject; an implementation detail that is not part of the public API.
 *
 * @internal
 * @since  2.0.0
 */
final readonly class Helper
{
    /**
     * Join two parts with a colon.
     *
     * @param   string  $prefix   Marker.
     * @param   string  $subject  Subject name.
     *
     * @return  string  `<prefix>: <subject>`.
     *
     * @since   2.0.0
     */
    public static function join(string $prefix, string $subject): string
    {
        return $prefix . ': ' . $subject;
    }
}
