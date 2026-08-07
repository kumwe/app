<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

enum DefinitionOwnerType: string
{
    case Core = 'core';
    case Extension = 'extension';
    case Site = 'site';
}
