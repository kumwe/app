<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Application;

use RuntimeException;

/**
 * Raised when a resolved pattern cannot be rendered with the values a caller supplied.
 *
 * A pattern that does not compile is a defect in a catalogue, and a parameter bag that cannot
 * satisfy one is a defect in a call site. Both are reported rather than swallowed: rendering the
 * raw pattern instead would put `{count, plural, one {...} other {...}}` in front of an operator,
 * and rendering an empty string would hide the fault until someone noticed a blank label. The
 * message names the identifier and the locale so the failing catalogue entry can be found without
 * reproducing the request.
 *
 * @since  2.0.0
 */
final class MessageFormattingFailed extends RuntimeException
{
    /**
     * State that the intl extension could not compile or apply a pattern.
     *
     * @param   string  $locale   Locale the pattern was being rendered for.
     * @param   string  $reason   Diagnostic the intl extension reported.
     * @param   string  $context  Message identifier the pattern came from, or a description of the
     *          caller when the pattern was supplied directly.
     *
     * @return  self  Exception naming the locale, the source of the pattern and the diagnostic.
     *
     * @since   2.0.0
     */
    public static function pattern(string $locale, string $reason, string $context): self
    {
        return new self(sprintf(
            'The message %s cannot be formatted for locale %s: %s',
            $context,
            $locale,
            $reason === '' ? 'the intl extension reported no diagnostic.' : $reason,
        ));
    }
}
