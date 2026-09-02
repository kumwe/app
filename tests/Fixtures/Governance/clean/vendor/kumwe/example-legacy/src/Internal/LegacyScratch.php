<?php

declare(strict_types=1);

namespace Kumwe\ExampleLegacy\Internal;

use Kumwe\ExampleLegacy\LegacyFormat;

/**
 * Applies a case; an implementation detail the source scan must not export.
 *
 * @internal
 * @since  2.0.0
 */
final readonly class LegacyScratch
{
    /**
     * Apply the requested case.
     *
     * @param   string        $text    Description text.
     * @param   LegacyFormat  $format  Requested case.
     *
     * @return  string  The formatted text.
     *
     * @since   2.0.0
     */
    public static function apply(string $text, LegacyFormat $format): string
    {
        return $format === LegacyFormat::Upper ? strtoupper($text) : strtolower($text);
    }
}
