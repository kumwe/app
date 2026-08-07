<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

enum RelationshipKind: string
{
    case OneToOne = 'one_to_one';
    case ManyToOne = 'many_to_one';
    case OneToMany = 'one_to_many';
    case ManyToMany = 'many_to_many';
    case OwnedLineCollection = 'owned_line_collection';
}
