<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use InvalidArgumentException;
use JsonException;

final class CanonicalJson
{
    private const int ENCODE_FLAGS = JSON_PRESERVE_ZERO_FRACTION
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value): string
    {
        try {
            return json_encode(self::normalize($value), self::ENCODE_FLAGS);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The value cannot be represented as canonical JSON.', 0, $exception);
        }
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::normalize(...), $value);
            }

            ksort($value, SORT_STRING);

            foreach ($value as $key => $item) {
                $value[$key] = self::normalize($item);
            }

            return $value;
        }

        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('Canonical JSON does not support non-finite numbers.');
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException(
            sprintf('Canonical JSON does not support values of type "%s".', get_debug_type($value)),
        );
    }
}
