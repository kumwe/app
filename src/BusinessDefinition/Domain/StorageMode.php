<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

enum StorageMode: string
{
    case Relational = 'relational';
}
