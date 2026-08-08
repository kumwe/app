<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallation;

interface BusinessSchemaInstallationRepository
{
    public function find(string $definitionId): ?SchemaInstallation;

    public function save(SchemaInstallation $installation): void;

    /** @return list<SchemaInstallation> Current rows locked until the caller's transaction completes. */
    public function ownedByForUpdate(string $ownerIdentifier): array;

    public function remove(string $definitionId, string $siteIdentifier): void;
}
