<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

enum AggregateFunction: string
{
    case Count = 'count';
    case Sum = 'sum';
    case Minimum = 'min';
    case Maximum = 'max';
    case Average = 'avg';
}
