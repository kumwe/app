<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation;

use InvalidArgumentException;

enum ThemeSurface: string
{
    case Site = 'site';
    case Administrator = 'administrator';

    public static function optional(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)))
            ?? throw new InvalidArgumentException('A theme surface must be site or administrator.');
    }
}
