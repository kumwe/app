<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;

interface PackageDefinitionSynchronizer
{
    /**
     * @param list<FieldTypeDefinition> $fieldTypes
     * @param list<EntityTypeDefinition> $definitions
     */
    public function synchronize(
        string $extensionIdentifier,
        string $releaseVersion,
        SiteContext $site,
        array $fieldTypes,
        array $definitions,
        bool $active,
        string $actorId,
    ): void;

    public function setActive(string $extensionIdentifier, bool $active, string $actorId): void;
}
