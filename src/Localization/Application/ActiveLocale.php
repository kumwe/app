<?php

declare(strict_types=1);

namespace Kumwe\CMS\Localization\Application;

use Kumwe\CMS\Localization\Domain\LocaleTag;

/**
 * Request-scoped holder for the locale and translation scope the unit of work in flight resolved.
 *
 * A Twig function receives only its arguments, and a console command receives only its input, so
 * the locale a middleware negotiated has to reach them through something the container shares. This
 * is that something, and it is deliberately the same shape as `CorrelationContext`: the middleware
 * opens a unit of work on it, everything rendered while it is open reads the same locale, and the
 * middleware closes it again in a `finally` so a long-lived worker never carries one request's
 * language into the next one's output.
 *
 * Nothing resolves a locale here. It holds what `LocaleNegotiator` decided and nothing else, and it
 * answers with the source locale outside a unit of work so a boot-time render is never locale-less.
 *
 * @since  2.0.0
 */
final class ActiveLocale
{
    /**
     * Locale of the single unit of work in flight, or null outside one.
     *
     * @var    ?LocaleTag
     * @since  2.0.0
     */
    private ?LocaleTag $locale = null;

    /**
     * Override scope of the single unit of work in flight, or null outside one.
     *
     * @var    ?TranslationScope
     * @since  2.0.0
     */
    private ?TranslationScope $scope = null;

    /**
     * Bind the holder to the locale it answers with outside a unit of work.
     *
     * @param  SupportedLocales  $supported  Registry supplying the source locale as the standing default.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly SupportedLocales $supported)
    {
    }

    /**
     * Open a unit of work, replacing whatever the previous one left behind.
     *
     * @param   LocaleTag          $locale  Locale this unit of work renders in.
     * @param   ?TranslationScope  $scope   Site and organization whose overrides apply, or null for the default.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function begin(LocaleTag $locale, ?TranslationScope $scope = null): void
    {
        $this->locale = $locale;
        $this->scope = $scope ?? TranslationScope::default();
    }

    /**
     * Close the unit of work so nothing carries into the next one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function end(): void
    {
        $this->locale = null;
        $this->scope = null;
    }

    /**
     * The locale in flight.
     *
     * @return  LocaleTag  The negotiated locale, or the source locale outside a unit of work.
     *
     * @since   2.0.0
     */
    public function locale(): LocaleTag
    {
        return $this->locale ?? $this->supported->source();
    }

    /**
     * The override scope in flight.
     *
     * @return  TranslationScope  The negotiated scope, or the default site outside a unit of work.
     *
     * @since   2.0.0
     */
    public function scope(): TranslationScope
    {
        return $this->scope ?? TranslationScope::default();
    }
}
