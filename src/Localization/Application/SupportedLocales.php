<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Application;

use Kumwe\App\Localization\Domain\InvalidLocaleTag;
use Kumwe\App\Localization\Domain\LocaleTag;

/**
 * The locales this installation carries, in preference order, with `en-GB` as the source of record.
 *
 * Version 2 states nine: `en-GB`, `en-US`, `af`, `de`, `he`, `ar`, `es`, `pt-BR` and `zh-Hans`.
 * They are held in one place because three separate inputs — an explicit choice, an
 * `Accept-Language` header and the site's `default_locale` setting — all have to be reduced to a
 * locale that actually exists, and doing that reduction differently in three places is how a site
 * ends up rendering a language it does not carry. Order is preference order, so a request for a
 * language without a region resolves to the first variant declared for it: `en` becomes `en-GB`,
 * not `en-US`.
 *
 * The source locale is separate from the rest. Every message is authored in it, so it is the last
 * fallback before an identifier is returned as its own text.
 *
 * @since  2.0.0
 */
final readonly class SupportedLocales
{
    /**
     * The Version 2 language set, in preference order, with the source locale first.
     *
     * @var    non-empty-list<string>
     * @since  2.0.0
     */
    public const VERSION_TWO = ['en-GB', 'en-US', 'af', 'de', 'he', 'ar', 'es', 'pt-BR', 'zh-Hans'];

    /**
     * The locale every message is authored in.
     *
     * @var    string
     * @since  2.0.0
     */
    public const SOURCE = 'en-GB';

    /**
     * Normalised tags in preference order.
     *
     * @var    non-empty-list<LocaleTag>
     * @since  2.0.0
     */
    private array $locales;

    /**
     * Build a registry from a declared list of tags and a declared source.
     *
     * @param   list<string>  $tags    Locale tags this installation carries, in preference order.
     * @param   string        $source  Locale every message is authored in; must appear in $tags.
     *
     * @throws  InvalidLocaleTag  When a tag is malformed, or the source is not among the tags.
     *
     * @since   2.0.0
     */
    public function __construct(array $tags = self::VERSION_TWO, private string $source = self::SOURCE)
    {
        $locales = [];
        foreach ($tags as $tag) {
            $locales[] = LocaleTag::fromString($tag);
        }
        if ($locales === []) {
            throw InvalidLocaleTag::unsupported($source, []);
        }
        $this->locales = $locales;
        if ($this->exact(LocaleTag::fromString($source)) === null) {
            throw InvalidLocaleTag::unsupported($source, $this->tags());
        }
    }

    /**
     * The locale every message is authored in, and the last fallback before the identifier itself.
     *
     * @return  LocaleTag  The source locale.
     *
     * @since   2.0.0
     */
    public function source(): LocaleTag
    {
        return LocaleTag::fromString($this->source);
    }

    /**
     * Every carried locale, in preference order.
     *
     * @return  non-empty-list<LocaleTag>  The declared locales, normalised.
     *
     * @since   2.0.0
     */
    public function all(): array
    {
        return $this->locales;
    }

    /**
     * Every carried locale as its canonical string form.
     *
     * @return  non-empty-list<string>  Tags in preference order.
     *
     * @since   2.0.0
     */
    public function tags(): array
    {
        $tags = [];
        foreach ($this->locales as $locale) {
            $tags[] = $locale->toString();
        }

        /** @var non-empty-list<string> $tags */
        return $tags;
    }

    /**
     * Reduce a candidate tag to the carried locale that best serves it.
     *
     * An exact match wins. Failing that, the first carried locale sharing the candidate's language
     * subtag is used, which is what turns the shipped `default_locale` of `en` into `en-GB` without
     * anybody having to restate the setting. A candidate whose language is not carried at all
     * resolves to nothing, and the caller decides whether that is a refusal or a fall-through.
     *
     * @param   LocaleTag  $candidate  Tag offered by a request, a header or a setting.
     *
     * @return  ?LocaleTag  The carried locale to use, or null when the language is not carried.
     *
     * @since   2.0.0
     */
    public function best(LocaleTag $candidate): ?LocaleTag
    {
        $exact = $this->exact($candidate);
        if ($exact !== null) {
            return $exact;
        }
        foreach ($this->locales as $locale) {
            if ($locale->language === $candidate->language) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * Whether a tag names a carried locale exactly.
     *
     * @param   LocaleTag  $candidate  Tag to test.
     *
     * @return  bool  True when the exact tag is carried.
     *
     * @since   2.0.0
     */
    public function carries(LocaleTag $candidate): bool
    {
        return $this->exact($candidate) !== null;
    }

    /**
     * Find the carried locale whose canonical form equals a candidate's.
     *
     * @param   LocaleTag  $candidate  Tag to look for.
     *
     * @return  ?LocaleTag  The carried locale, or null when it is not carried.
     *
     * @since   2.0.0
     */
    private function exact(LocaleTag $candidate): ?LocaleTag
    {
        foreach ($this->locales as $locale) {
            if ($locale->equals($candidate)) {
                return $locale;
            }
        }

        return null;
    }
}
