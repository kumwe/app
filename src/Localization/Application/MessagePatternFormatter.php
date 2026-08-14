<?php

declare(strict_types=1);

namespace Kumwe\CMS\Localization\Application;

use Kumwe\CMS\Localization\Domain\LocaleTag;

/**
 * Port that substitutes a parameter bag into a resolved message pattern for one locale.
 *
 * It is separated from the translator because the two answer different questions: the translator
 * decides *which* pattern applies, and this decides *what the pattern says* once the values are
 * known. Plural category, gender selection, ordinal form, number, currency and date rendering are
 * all this port's responsibility, and all of them are locale-dependent in ways string substitution
 * cannot express — the languages in scope span one plural category, two, three and six, so a
 * formatter that substitutes rather than selects would be wrong in Arabic on every count it renders.
 *
 * @since  2.0.0
 */
interface MessagePatternFormatter
{
    /**
     * Format one pattern for one locale.
     *
     * @param   string                                                   $pattern     ICU MessageFormat pattern.
     * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Values the pattern names,
     *          keyed by placeholder name.
     * @param   LocaleTag                                                $locale      Locale whose plural rules,
     *          number symbols and date formats apply.
     *
     * @return  string  The formatted message.
     *
     * @throws  MessageFormattingFailed  When the pattern does not compile, or the parameters cannot
     *          satisfy it.
     *
     * @since   2.0.0
     */
    public function format(string $pattern, array $parameters, LocaleTag $locale): string;
}
