<?php

declare(strict_types=1);

namespace Kumwe\CMS\Localization\Application;

use Kumwe\CMS\Localization\Domain\LocaleTag;

/**
 * Validates an ICU MessageFormat pattern before it reaches a durable catalogue layer.
 *
 * Formatting is intentionally a request-time concern, but syntax validity is a write-time and build-time
 * invariant: one malformed override or compiled translation must be refused before every surface that
 * looks the identifier up begins throwing. The port keeps the application service independent of `intl`.
 *
 * @since  2.0.0
 */
interface MessagePatternValidator
{
    /**
     * Refuse a pattern ICU cannot compile for its declared locale.
     *
     * @param   string     $pattern  Candidate ICU MessageFormat pattern.
     * @param   LocaleTag  $locale   Locale whose grammar and plural rules the pattern targets.
     *
     * @return  void
     *
     * @throws  MessageFormattingFailed  When the pattern cannot be compiled for the locale.
     *
     * @since   2.0.0
     */
    public function validate(string $pattern, LocaleTag $locale): void;
}
