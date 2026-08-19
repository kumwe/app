<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Infrastructure;

use RuntimeException;

/**
 * Raised at construction when the intl extension the message formatter needs is not loaded.
 *
 * The failure is loud and immediate rather than a quiet fall-back to a substituting formatter,
 * because a substituting formatter is wrong rather than approximate: the languages in scope span
 * one plural category, two, three and six, so `sprintf`-shaped substitution would render Arabic
 * counts incorrectly on every page and nothing would report it. `ext-intl` is a declared, hard
 * requirement of this package, so an installation without it is misconfigured, and the message
 * says exactly that.
 *
 * @since  2.0.0
 */
final class IntlExtensionMissing extends RuntimeException
{
    /**
     * State that the extension is absent and what has to be done about it.
     *
     * @return  self  Exception naming the missing extension and the consequence of running without it.
     *
     * @since   2.0.0
     */
    public static function forMessageFormatting(): self
    {
        return new self(
            'The intl extension is required for interface translation and is not loaded. '
                . 'Install or enable ext-intl; message formatting is not degraded to a substituting '
                . 'formatter because plural and ordinal selection cannot be expressed without it.',
        );
    }
}
