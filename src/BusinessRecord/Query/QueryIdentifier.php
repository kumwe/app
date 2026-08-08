<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use InvalidArgumentException;

final class QueryIdentifier
{
    public static function assertField(string $value): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $value) !== 1) {
            throw new InvalidArgumentException('A business-record query identifier is invalid.');
        }
    }

    private function __construct()
    {
    }
}
