<?php

declare(strict_types=1);

namespace Kumwe\CMS\Shared\Domain;

final class DatabaseTablePrefix
{
    public const MAXIMUM_BYTES = 28;

    public static function isValid(string $prefix): bool
    {
        return strlen($prefix) <= self::MAXIMUM_BYTES
            && preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*_$/D', $prefix) === 1;
    }
}
