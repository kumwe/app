<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallation;

final readonly class ResolvedBusinessDefinition
{
    public function __construct(
        public EntityTypeDefinition $definition,
        public SchemaInstallation $installation,
    ) {
        if (
            $definition->id !== $installation->definitionId
            || $definition->definitionVersion > $installation->definitionVersion
            || $definition->siteIdentifier !== $installation->siteIdentifier
        ) {
            throw new InvalidArgumentException('A resolved business definition and installed schema are inconsistent.');
        }
        if (
            $definition->definitionVersion === $installation->definitionVersion
            && !hash_equals($definition->checksum(), $installation->definitionChecksum)
        ) {
            throw new InvalidArgumentException('A resolved definition checksum differs from the installed schema.');
        }
    }
}
