<?php

declare(strict_types=1);

namespace Kumwe\CMS\Site\Application;

interface SiteSettings
{
    /** @return array{site_name: string, homepage_slug: string} */
    public function current(): array;

    public function update(string $actorId, string $siteName, string $homepageSlug): void;
}
