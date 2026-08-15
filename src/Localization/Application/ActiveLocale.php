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
     * Monotonic identity of the locale unit of work.
     *
     * A shared translator uses this value to keep catalogue memoization inside one request. Incrementing
     * at both edges means translations performed outside a request cannot reuse a chain assembled while
     * an earlier request was open, which matters after an operator changes administered wording.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $generation = 0;

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
        ++$this->generation;
        $this->locale = $locale;
        $this->scope = $scope ?? TranslationScope::default();
    }

    /**
     * Adopt the locale named by a language-bearing resource while preserving its trusted scope.
     *
     * Negotiation decides language-neutral entry points. A resolved content path already names one
     * locale, so delivery calls this before rendering to keep the document language, translated chrome
     * and content together without discarding the site or organization override scope.
     *
     * @param   LocaleTag  $locale  Locale the resolved resource declares.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function adoptLocale(LocaleTag $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * Replace the override scope after authentication resolves trusted organization membership.
     *
     * Locale negotiation deliberately runs before authentication, so it can only open a site scope.
     * The later scope middleware calls this with the authenticated execution context while leaving the
     * already-negotiated locale untouched.
     *
     * @param   TranslationScope  $scope  Trusted site and optional organization for downstream lookups.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function adoptScope(TranslationScope $scope): void
    {
        $this->scope = $scope;
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
        ++$this->generation;
        $this->locale = null;
        $this->scope = null;
    }

    /**
     * Identify the locale unit of work whose catalogue chains may be reused.
     *
     * @return  int  Value that changes whenever a request locale is opened or closed.
     *
     * @since   2.0.0
     */
    public function generation(): int
    {
        return $this->generation;
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
