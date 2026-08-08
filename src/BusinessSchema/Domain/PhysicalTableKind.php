<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

enum PhysicalTableKind: string
{
    case Entity = 'entity';
    case Relation = 'relation';
    case Junction = 'junction';
    case OwnedLine = 'owned_line';
}
