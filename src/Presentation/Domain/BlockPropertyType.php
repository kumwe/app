<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Domain;

enum BlockPropertyType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Number = 'number';
    case Boolean = 'boolean';
    case Object = 'object';
    case List = 'list';

    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::String => is_string($value) && mb_check_encoding($value, 'UTF-8'),
            self::Integer => is_int($value),
            self::Number => (is_int($value) || is_float($value))
                && (!is_float($value) || is_finite($value)),
            self::Boolean => is_bool($value),
            self::Object => is_array($value) && !array_is_list($value),
            self::List => is_array($value) && array_is_list($value),
        };
    }
}
