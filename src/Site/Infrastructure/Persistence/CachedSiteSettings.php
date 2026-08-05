<?php

declare(strict_types=1);

namespace Kumwe\CMS\Site\Infrastructure\Persistence;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Infrastructure\Redis\RedisRuntime;
use Kumwe\CMS\Site\Application\SiteSettings;

final readonly class CachedSiteSettings implements SiteSettings
{
    private const string CACHE_KEY = 'site-settings';

    public function __construct(private DoctrineSiteSettings $settings, private RedisRuntime $redis)
    {
    }

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

    public function managed(ExecutionContext $context): array
    {
        return $this->settings->managed($context);
    }

    public function update(ExecutionContext $context, string $siteName, string $homepageSlug): void
    {
        $this->settings->update($context, $siteName, $homepageSlug);
        $this->redis->forgetCache(self::CACHE_KEY);
    }

    public function updateAll(ExecutionContext $context, array $settings): void
    {
        $this->settings->updateAll($context, $settings);
        $this->redis->forgetCache(self::CACHE_KEY);
    }
}
