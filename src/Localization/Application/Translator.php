<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Application;

use Kumwe\App\Localization\Domain\LocaleTag;

/**
 * Port every layer reads user-facing text through, given an identifier, a parameter bag and a locale.
 *
 * This is the whole translation contract as a caller sees it. The locale is an argument rather than
 * process state, and that is the load-bearing decision: this platform runs long-lived queue workers
 * and a scheduler, so a job for a site in Arabic and the next job for a site in German are handled
 * by the same process, and a selector held in the process would leak the first job's language into
 * the second. Passing the locale means two units of work in one worker cannot contaminate each
 * other, and it is the reason the operating-system locale is never consulted.
 *
 * An implementation resolves the identifier through the override chain, formats the result through
 * ICU MessageFormat, and never returns an empty string: a message the catalogues do not carry comes
 * back as its own identifier, because a visibly untranslated interface is recoverable and a silently
 * blank one is not.
 *
 * @since  2.0.0
 */
interface Translator
{
    /**
     * Resolve and format one message.
     *
     * @param   string                                                   $identifier  Stable message identifier.
     * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Values the ICU pattern
     *          names, keyed by placeholder name.
     * @param   ?LocaleTag                                               $locale      Locale to render in, or
     *          null to use the locale resolved for the unit of work in flight.
     *
     * @return  string  The formatted message, or the identifier itself when no layer carries it.
     *
     * @throws  \Kumwe\App\Localization\Domain\InvalidMessageIdentifier  When the identifier does not
     *          satisfy the frozen grammar.
     * @throws  MessageFormattingFailed  When the resolved pattern is not valid ICU MessageFormat, or
     *          the supplied parameters cannot satisfy it.
     *
     * @since   2.0.0
     */
    public function translate(string $identifier, array $parameters = [], ?LocaleTag $locale = null): string;

    /**
     * Whether any layer of the chain carries a message for an identifier at a locale.
     *
     * Callers that must distinguish "translated to the identifier" from "genuinely translated" — an
     * extraction gate, a catalogue completeness check — ask this rather than comparing the returned
     * string against the identifier.
     *
     * @param   string      $identifier  Stable message identifier.
     * @param   ?LocaleTag  $locale      Locale to test, or null for the locale in flight.
     *
     * @return  bool  True when a pattern exists at this locale or one of its fallbacks.
     *
     * @since   2.0.0
     */
    public function has(string $identifier, ?LocaleTag $locale = null): bool;
}
