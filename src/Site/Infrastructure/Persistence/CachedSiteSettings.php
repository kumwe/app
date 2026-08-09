<?php

declare(strict_types=1);

namespace Kumwe\CMS\Site\Infrastructure\Persistence;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Infrastructure\Redis\RedisRuntime;
use Kumwe\CMS\Site\Application\SiteSettings;

/**
 * Redis read cache in front of `DoctrineSiteSettings`, wired as the `SiteSettings` the container hands out.
 *
 * Nearly every public request reads the settings document — for the homepage nomination, the primary
 * menu handle and the presentation contract — and none of those reads has an actor, so the
 * unauthenticated `current()` answer is held in Redis for five minutes and only a miss reaches SQL.
 * Nothing else is cached: the administration read is capability-checked per call, and both writers go
 * straight to the database and drop the cached copy afterwards, so a saved change shows up on the next
 * request instead of when the entry expires. Dropping after the write rather than before is what keeps
 * a rejected write from leaving readers on a cache that disagrees with the table. SQL stays the source
 * of truth; the cache only saves the query.
 *
 * @since  2.0.0
 */
final readonly class CachedSiteSettings implements SiteSettings
{
    /**
     * Redis key the settings document lives under, shared by the read and both invalidations.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string CACHE_KEY = 'site-settings';

    /**
     * Bind the cache to the database-backed settings it fronts.
     *
     * @param  DoctrineSiteSettings  $settings  Authoritative store every miss and every write goes to.
     * @param  RedisRuntime          $redis     Cache runtime holding the decoded document between reads.
     *
     * @since  2.0.0
     */
    public function __construct(private DoctrineSiteSettings $settings, private RedisRuntime $redis)
    {
    }

    /**
     * Read the settings document, from Redis when it is warm and from the database otherwise.
     *
     * A miss repopulates the cache with a five-minute lifetime, so a burst of public requests after an
     * expiry costs one query rather than one per request.
     *
     * @return  array<string, mixed>  Every public setting key, defaults included for keys never stored.
     *
     * @throws  \JsonException  When the cached document cannot be decoded, or the fresh one encoded.
     * @throws  \RuntimeException  When Redis holds something that is not a settings document, or will
     *          not store one.
     *
     * @since   2.0.0
     */
    public function current(): array
    {
        $cached = $this->redis->cachedJson(self::CACHE_KEY);
        if ($cached !== null) {
            return $cached;
        }

        $settings = $this->settings->current();
        $this->redis->cacheJson(self::CACHE_KEY, $settings, 300);

        return $settings;
    }

    /**
     * Read the settings document for an administrator, bypassing the cache entirely.
     *
     * The capability check depends on the actor, so a cached answer would either be served to the
     * wrong one or skip the check; the delegate answers every call directly.
     *
     * @param   ExecutionContext  $context  Actor and site the read runs as.
     *
     * @return  array<string, mixed>  Every public setting key, defaults included for keys never stored.
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not manage settings.
     *
     * @since   2.0.0
     */
    public function managed(ExecutionContext $context): array
    {
        return $this->settings->managed($context);
    }

    /**
     * Store the site name and homepage slug in the database, then drop the cached document.
     *
     * Invalidation runs only after the write has returned, so a refused or failed update leaves the
     * cached copy in place and still matching what is stored.
     *
     * @param   ExecutionContext  $context       Actor and site the write runs as.
     * @param   string            $siteName      Display name shown in page chrome and titles.
     * @param   string            $homepageSlug  Slug the homepage falls back to when no content id is set.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not manage settings.
     * @throws  \InvalidArgumentException  When the name or the slug fails validation.
     *
     * @since   2.0.0
     */
    public function update(ExecutionContext $context, string $siteName, string $homepageSlug): void
    {
        $this->settings->update($context, $siteName, $homepageSlug);
        $this->redis->forgetCache(self::CACHE_KEY);
    }

    /**
     * Merge and store the supplied settings in the database, then drop the cached document.
     *
     * As with `update()`, the cache is invalidated only once the write has committed, so a rejected
     * document never leaves readers looking at an entry that disagrees with the table.
     *
     * @param   ExecutionContext      $context   Actor and site the write runs as.
     * @param   array<string, mixed>  $settings  Public setting keys to change, merged over the current document.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When the actor may not manage settings.
     * @throws  \InvalidArgumentException  When a value, the nominated homepage, or the primary menu is
     *          rejected.
     *
     * @since   2.0.0
     */
    public function updateAll(ExecutionContext $context, array $settings): void
    {
        $this->settings->updateAll($context, $settings);
        $this->redis->forgetCache(self::CACHE_KEY);
    }
}
