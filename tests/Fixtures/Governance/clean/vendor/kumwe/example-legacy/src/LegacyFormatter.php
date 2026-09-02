<?php

declare(strict_types=1);

namespace Kumwe\ExampleLegacy;

use Kumwe\ExampleLegacy\Internal\LegacyScratch;

/**
 * Formats a legacy description in one of the supported cases.
 *
 * @since  2.0.0
 */
final readonly class LegacyFormatter
{
    /**
     * Format a description.
     *
     * @param   string        $text    Description text.
     * @param   LegacyFormat  $format  Requested case.
     *
     * @return  string  The formatted text.
     *
     * @since   2.0.0
     */
    public function format(string $text, LegacyFormat $format): string
    {
        return LegacyScratch::apply($text, $format);
    }
}
