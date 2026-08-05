<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use InvalidArgumentException;
use JsonException;

final class RuntimeCanonicalJson
{
    public static function encode(mixed $value): string
    {
        try {
            return json_encode(
                self::canonicalize($value),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Runtime state cannot be represented as canonical JSON.', 0, $exception);
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return (object) $value;
    }
}
