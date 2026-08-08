<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallationStatus;

interface BusinessSchemaExecutionStateGuard
{
    public function lockOwner(
        SiteContext $site,
        string $definitionId,
        string $ownerIdentifier,
        bool $activeRequired,
    ): bool;

    public function lockInstallationStatus(string $definitionId): ?SchemaInstallationStatus;
}
