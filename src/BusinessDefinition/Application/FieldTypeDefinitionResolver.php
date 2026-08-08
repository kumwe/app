<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;

/** Resolves immutable field-type structure without implying that its owner is executable. */
interface FieldTypeDefinitionResolver
{
    public function get(string $identifier): FieldTypeDefinition;
}
