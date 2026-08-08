<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalSchemaBlueprint;

interface DefinitionPhysicalSchemaCompiler
{
    public function compile(EntityTypeDefinition $definition, SiteContext $site): PhysicalSchemaBlueprint;
}
