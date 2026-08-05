<?php

declare(strict_types=1);

namespace Kumwe\CMS\Site\Application;

use Kumwe\CMS\Application\Authorization\ExecutionContext;

interface SiteSettings
{
    /** @return array<string, mixed> */
    public function current(): array;

    /** @return array<string, mixed> */
    public function managed(ExecutionContext $context): array;

    public function update(ExecutionContext $context, string $siteName, string $homepageSlug): void;

    /** @param array<string, mixed> $settings */
    public function updateAll(ExecutionContext $context, array $settings): void;
}
