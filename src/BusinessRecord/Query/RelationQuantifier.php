<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

enum RelationQuantifier: string
{
    case Any = 'any';
    case None = 'none';
    case All = 'all';
}
