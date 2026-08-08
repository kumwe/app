<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

enum BooleanOperator: string
{
    case All = 'all';
    case Any = 'any';
    case Not = 'not';
}
