<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Infrastructure;

use Kumwe\App\Localization\Application\MessageFormattingFailed;
use Kumwe\App\Localization\Application\MessagePatternFormatter;
use Kumwe\App\Localization\Application\MessagePatternValidator;
use Kumwe\App\Localization\Domain\LocaleTag;
use MessageFormatter;

/**
 * Formats ICU MessageFormat patterns through the intl extension, one locale per call.
 *
 * ICU is what makes the nine languages expressible in one pipeline. Plural category selection is
 * the arithmetic reason: `zh-Hans` has one category, the European set has two, `he` has three
 * because it distinguishes a dual, and `ar` has six because it distinguishes zero, one, two, few,
 * many and other. Ordinals, gender selection, number and currency symbols and date skeletons are
 * locale-dependent in the same way. Adding a language whose plural class is not yet represented —
 * the four-category Slavic class is the next step outward — is therefore a catalogue change and not
 * an engineering change.
 *
 * The locale is a call argument, never process state. `setlocale()` is never used and the operating
 * system's locales are never consulted, so a worker draining a queue can format one job in Arabic
 * and the next in German with no possibility of the first leaking into the second.
 *
 * @since  2.0.0
 */
final readonly class IntlMessagePatternFormatter implements MessagePatternFormatter, MessagePatternValidator
{
    /**
     * Refuse to exist without the extension every format call depends on.
     *
     * @throws  IntlExtensionMissing  When `ext-intl` is not loaded.
     *
     * @since   2.0.0
     */
    public function __construct()
    {
        if (!extension_loaded('intl')) {
            throw IntlExtensionMissing::forMessageFormatting();
        }
    }

    /**
     * Format one pattern for one locale.
     *
     * Boolean parameters are rendered as `1` and `0` by ICU, which is rarely what a message means,
     * so they are converted to the strings `true` and `false` before they reach the formatter. That
     * makes a boolean usable as a `select` argument, which is how a message that varies on a flag
     * is written.
     *
     * @param   string                                                   $pattern     ICU MessageFormat pattern.
     * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Values the pattern names,
     *          keyed by placeholder name.
     * @param   LocaleTag                                                $locale      Locale whose plural rules,
     *          number symbols and date formats apply.
     *
     * @return  string  The formatted message.
     *
     * @throws  MessageFormattingFailed  When the pattern does not compile for this locale, or the
     *          parameters cannot satisfy it.
     *
     * @since   2.0.0
     */
    public function format(string $pattern, array $parameters, LocaleTag $locale): string
    {
        $tag = $locale->toString();
        $formatter = MessageFormatter::create($tag, $pattern);
        if (!$formatter instanceof MessageFormatter) {
            throw MessageFormattingFailed::pattern($tag, intl_get_error_message(), 'pattern');
        }

        $values = [];
        foreach ($parameters as $name => $value) {
            $values[$name] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
        }

        $formatted = $formatter->format($values);
        if (!is_string($formatted)) {
            throw MessageFormattingFailed::pattern($tag, $formatter->getErrorMessage(), 'pattern');
        }

        return $formatted;
    }

    /**
     * Compile a pattern without formatting it, so missing runtime parameters are not mistaken for bad syntax.
     *
     * @param   string     $pattern  Candidate ICU MessageFormat pattern.
     * @param   LocaleTag  $locale   Locale whose grammar and plural rules the pattern targets.
     *
     * @return  void
     *
     * @throws  MessageFormattingFailed  When ICU refuses the pattern.
     *
     * @since   2.0.0
     */
    public function validate(string $pattern, LocaleTag $locale): void
    {
        $tag = $locale->toString();
        if (!MessageFormatter::create($tag, $pattern) instanceof MessageFormatter) {
            throw MessageFormattingFailed::pattern($tag, intl_get_error_message(), 'pattern');
        }
    }
}
