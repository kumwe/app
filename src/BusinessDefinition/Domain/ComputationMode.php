<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

enum ComputationMode: string
{
    case Virtual = 'virtual';
    case Stored = 'stored';
}
