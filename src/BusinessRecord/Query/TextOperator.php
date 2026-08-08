<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

enum TextOperator: string
{
    case Contains = 'contains';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
}
