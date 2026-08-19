<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Application;

use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Localization\Domain\MessageCatalogue;
use Kumwe\App\Localization\Domain\MessageCatalogueChain;
use Kumwe\App\Localization\Domain\MessageCatalogueLayer;
use Kumwe\App\Localization\Domain\MessageIdentifier;

/**
 * Resolves a message through the four-layer override chain and formats it with ICU MessageFormat.
 *
 * Lookup walks two axes. The outer one is the locale and its fallbacks — `pt-BR`, then `pt`, then
 * the source locale — and the inner one is the override chain, organization before site before
 * extension before core. A locale-specific override therefore beats a source-locale core message,
 * which is what an operator expects when they change a word for one language only.
 *
 * A message no layer carries comes back as its own identifier. That is deliberate and is the
 * difference between an interface that is visibly missing a translation and one that is silently
 * blank: the first is a defect anybody can see and report, the second is a defect nobody notices
 * until a customer does.
 *
 * Each locale-and-scope pair is assembled once per active unit of work, so a page that resolves several
 * hundred messages performs one catalogue load rather than several hundred. `ActiveLocale` generations
 * bound that memoization even when the shared container and translator serve several sequential requests.
 *
 * @since  2.0.0
 */
final class CatalogueTranslator implements Translator
{
    /**
     * Assembled chains, keyed by locale tag and override scope.
     *
     * @var    array<string, MessageCatalogueChain>
     * @since  2.0.0
     */
    private array $chains = [];

    /**
     * Active-locale generation the memoized chains belong to.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $generation = -1;

    /**
     * Bind the translator to its catalogue sources, its formatter and the locale in flight.
     *
     * @param  MessageCatalogueRepository  $catalogues  Source of the core and extension layers.
     * @param  MessageOverrideRepository   $overrides   Source of the site and organization layers.
     * @param  MessagePatternFormatter     $formatter   ICU formatter every resolved pattern goes through.
     * @param  ActiveLocale                $active      Holder of the locale and scope of the unit of work.
     * @param  SupportedLocales            $supported   Registry supplying the source locale as last resort.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly MessageCatalogueRepository $catalogues,
        private readonly MessageOverrideRepository $overrides,
        private readonly MessagePatternFormatter $formatter,
        private readonly ActiveLocale $active,
        private readonly SupportedLocales $supported,
    ) {
    }

    /**
     * Resolve and format one message.
     *
     * @param   string                                                   $identifier  Stable message identifier.
     * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Values the ICU pattern
     *          names, keyed by placeholder name.
     * @param   ?LocaleTag                                               $locale      Locale to render in, or
     *          null for the locale of the unit of work in flight.
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
    public function translate(string $identifier, array $parameters = [], ?LocaleTag $locale = null): string
    {
        $validated = MessageIdentifier::fromString($identifier);
        $target = $locale ?? $this->active->locale();
        $pattern = $this->pattern($validated->value, $target);
        if ($pattern === null) {
            return $validated->value;
        }

        return $this->formatter->format($pattern, $parameters, $target);
    }

    /**
     * Whether any layer of the chain carries a message for an identifier at a locale.
     *
     * @param   string      $identifier  Stable message identifier.
     * @param   ?LocaleTag  $locale      Locale to test, or null for the locale in flight.
     *
     * @return  bool  True when a pattern exists at this locale or one of its fallbacks.
     *
     * @throws  \Kumwe\App\Localization\Domain\InvalidMessageIdentifier  When the identifier does not
     *          satisfy the frozen grammar.
     *
     * @since   2.0.0
     */
    public function has(string $identifier, ?LocaleTag $locale = null): bool
    {
        $validated = MessageIdentifier::fromString($identifier);

        return $this->pattern($validated->value, $locale ?? $this->active->locale()) !== null;
    }

    /**
     * Which layer answers for an identifier at a locale, and at which locale it was found.
     *
     * An administration surface showing an operator why a word reads the way it does needs both
     * halves of the answer, and so does a test proving the chain resolves in the declared order.
     *
     * @param   string      $identifier  Stable message identifier.
     * @param   ?LocaleTag  $locale      Locale to attribute, or null for the locale in flight.
     *
     * @return  ?array{layer: MessageCatalogueLayer, locale: string}  The winning layer and the locale
     *          it was found at, or null when no layer carries the identifier.
     *
     * @since   2.0.0
     */
    public function attribution(string $identifier, ?LocaleTag $locale = null): ?array
    {
        $target = $locale ?? $this->active->locale();
        foreach ($this->searchOrder($target) as $tag) {
            $chain = $this->chain($tag);
            $layer = $chain->winningLayer($identifier);
            if ($layer instanceof MessageCatalogueLayer) {
                return ['layer' => $layer, 'locale' => $tag];
            }
        }

        return null;
    }

    /**
     * Walk the locale fallbacks, then the source locale, returning the first pattern found.
     *
     * @param   string     $identifier  Validated message identifier.
     * @param   LocaleTag  $locale      Locale the caller asked for.
     *
     * @return  ?string  The winning ICU pattern, or null when nothing carries the identifier.
     *
     * @since   2.0.0
     */
    private function pattern(string $identifier, LocaleTag $locale): ?string
    {
        foreach ($this->searchOrder($locale) as $tag) {
            $pattern = $this->chain($tag)->resolve($identifier);
            if ($pattern !== null) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * The locale tags a lookup tries, most specific first, ending at the source locale.
     *
     * @param   LocaleTag  $locale  Locale the caller asked for.
     *
     * @return  non-empty-list<string>  Tags to try, without repeats.
     *
     * @since   2.0.0
     */
    private function searchOrder(LocaleTag $locale): array
    {
        $order = array_merge($locale->fallbacks(), $this->supported->source()->fallbacks());

        /** @var non-empty-list<string> $unique */
        $unique = array_values(array_unique($order));

        return $unique;
    }

    /**
     * Assemble the four layers for one locale tag, or return the assembly already held.
     *
     * @param   string  $tag  Canonical locale tag to assemble.
     *
     * @return  MessageCatalogueChain  The chain, most specific layer first.
     *
     * @since   2.0.0
     */
    private function chain(string $tag): MessageCatalogueChain
    {
        $generation = $this->active->generation();
        if ($generation !== $this->generation) {
            $this->chains = [];
            $this->generation = $generation;
        }
        $scope = $this->active->scope();
        $key = $tag . '@' . $scope->key();
        if (isset($this->chains[$key])) {
            return $this->chains[$key];
        }

        $locale = LocaleTag::fromString($tag);
        $organization = $scope->organization;
        $layers = [
            new MessageCatalogue(
                $locale,
                MessageCatalogueLayer::Organization,
                $organization === null
                    ? []
                    : $this->overrides->organizationOverrides($scope->site, $organization, $locale),
            ),
            new MessageCatalogue(
                $locale,
                MessageCatalogueLayer::Site,
                $this->overrides->siteOverrides($scope->site, $locale),
            ),
            $this->catalogues->catalogue(MessageCatalogueLayer::Extension, $locale),
            $this->catalogues->catalogue(MessageCatalogueLayer::Core, $locale),
        ];

        return $this->chains[$key] = new MessageCatalogueChain($locale, $layers);
    }
}
