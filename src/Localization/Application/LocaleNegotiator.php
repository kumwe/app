<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Application;

use Kumwe\App\Localization\Domain\InvalidLocaleTag;
use Kumwe\App\Localization\Domain\LocaleTag;

/**
 * Decides which of the carried locales a unit of work renders in.
 *
 * Three inputs are consulted, in this order, and the first that names a carried locale wins: an
 * explicit choice the caller made, the languages the caller's client says it accepts, and the
 * site's `default_locale` setting. The order is what makes each input mean something — an explicit
 * choice is a decision and must not be overruled by a browser preference, a browser preference is
 * better than a guess, and the site setting is the operator's answer for everyone who expressed no
 * preference at all. When none of the three names a carried locale the source locale is used, so
 * negotiation always produces a locale and never a null a caller has to handle.
 *
 * Nothing here reads process state and nothing here writes any. The result is returned to the
 * caller, which is what keeps two units of work in one long-lived worker from sharing a language.
 *
 * @since  2.0.0
 */
final readonly class LocaleNegotiator
{
    /**
     * Bind negotiation to the carried locales and the site's administered default.
     *
     * @param  SupportedLocales   $supported      Registry every candidate is reduced against.
     * @param  SiteDefaultLocale  $siteDefault    Consumer of the site's `default_locale` setting.
     * @param  int                $maximumHeader  Longest `Accept-Language` header that will be parsed,
     *         in bytes; a longer one is ignored rather than walked.
     *
     * @since  2.0.0
     */
    public function __construct(
        private SupportedLocales $supported,
        private SiteDefaultLocale $siteDefault,
        private int $maximumHeader = 512,
    ) {
    }

    /**
     * Resolve the locale for one unit of work.
     *
     * @param   ?string  $explicit        Locale the caller asked for outright, or null when it asked
     *          for none. An unparseable or uncarried value is ignored rather than refused, because a
     *          stale bookmark must not turn a page into an error.
     * @param   string   $acceptLanguage  Raw `Accept-Language` header, empty when absent.
     *
     * @return  LocaleTag  A locale this installation carries.
     *
     * @since   2.0.0
     */
    public function negotiate(?string $explicit, string $acceptLanguage = ''): LocaleTag
    {
        if ($explicit !== null) {
            $chosen = $this->reduce($explicit);
            if ($chosen instanceof LocaleTag) {
                return $chosen;
            }
        }

        foreach ($this->acceptedLanguages($acceptLanguage) as $candidate) {
            $accepted = $this->reduce($candidate);
            if ($accepted instanceof LocaleTag) {
                return $accepted;
            }
        }

        return $this->siteDefault->locale();
    }

    /**
     * Reduce one candidate string to a carried locale.
     *
     * @param   string  $candidate  Language tag as a caller or a client wrote it.
     *
     * @return  ?LocaleTag  The carried locale, or null when the value is malformed or not carried.
     *
     * @since   2.0.0
     */
    private function reduce(string $candidate): ?LocaleTag
    {
        try {
            return $this->supported->best(LocaleTag::fromString($candidate));
        } catch (InvalidLocaleTag) {
            return null;
        }
    }

    /**
     * Split an `Accept-Language` header into its tags, best quality first.
     *
     * Quality values are honoured because that is what the header means, and a tag with `q=0` is
     * dropped because that is a client saying it will not accept the language. The wildcard is
     * ignored rather than resolved: it means "anything", and the site's own setting is a better
     * answer to that than the first locale in a list. The header is bounded before it is walked, so
     * a client cannot make negotiation expensive by sending a very long one.
     *
     * @param   string  $header  Raw header value, empty when absent.
     *
     * @return  list<string>  Language tags in descending quality order, ties keeping header order.
     *
     * @since   2.0.0
     */
    private function acceptedLanguages(string $header): array
    {
        $header = trim($header);
        if ($header === '' || strlen($header) > $this->maximumHeader) {
            return [];
        }

        $ranked = [];
        $position = 0;
        foreach (explode(',', $header) as $part) {
            $pieces = explode(';', $part);
            $tag = trim($pieces[0]);
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $quality = 1.0;
            foreach (array_slice($pieces, 1) as $parameter) {
                $parameter = trim($parameter);
                if (str_starts_with($parameter, 'q=')) {
                    $quality = (float) substr($parameter, 2);
                }
            }
            if ($quality <= 0.0) {
                continue;
            }
            $ranked[] = ['tag' => $tag, 'quality' => $quality, 'position' => $position++];
        }

        usort($ranked, static function (array $left, array $right): int {
            $byQuality = $right['quality'] <=> $left['quality'];

            return $byQuality === 0 ? $left['position'] <=> $right['position'] : $byQuality;
        });

        return array_column($ranked, 'tag');
    }
}
