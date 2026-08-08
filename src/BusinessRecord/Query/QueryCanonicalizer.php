<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use Kumwe\CMS\BusinessRecord\Domain\RecordValueGuard;

final class QueryCanonicalizer
{
    public static function value(mixed $value): mixed
    {
        return RecordValueGuard::canonical($value);
    }

    private function __construct()
    {
    }
}
