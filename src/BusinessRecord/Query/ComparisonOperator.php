<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

enum ComparisonOperator: string
{
    case Equal = 'eq';
    case NotEqual = 'ne';
    case LessThan = 'lt';
    case LessThanOrEqual = 'lte';
    case GreaterThan = 'gt';
    case GreaterThanOrEqual = 'gte';
}
