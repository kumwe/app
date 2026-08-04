<?php

declare(strict_types=1);

namespace Kumwe\CMS\Site\Application;

interface SiteSettings
{
    /** @return array<string, mixed> */
    public function current(): array;

    public function update(string $actorId, string $siteName, string $homepageSlug): void;

    /** @param array<string, mixed> $settings */
    public function updateAll(string $actorId, array $settings): void;
}
