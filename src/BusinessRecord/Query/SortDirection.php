<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

enum SortDirection: string
{
    case Ascending = 'asc';
    case Descending = 'desc';
}
