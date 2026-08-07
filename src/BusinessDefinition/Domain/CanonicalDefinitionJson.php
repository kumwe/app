<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

use JsonException;

final class CanonicalDefinitionJson
{
    public static function encode(mixed $value): string
    {
        self::assertSafe($value);

        try {
            return json_encode(
                self::normalize($value),
                JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidBusinessDefinition('The business definition is not JSON serializable.', 0, $exception);
        }
    }

    public static function checksum(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }
        return $value;
    }

    private static function assertSafe(mixed $value, int $depth = 0): void
    {
        if ($depth > 32) {
            throw new InvalidBusinessDefinition('A business definition exceeds the maximum nesting depth.');
        }
        if (is_float($value) || is_resource($value) || is_object($value)) {
            throw new InvalidBusinessDefinition('Business definitions cannot contain floats, resources, or objects.');
        }
        if (!is_array($value)) {
            return;
        }
        if (count($value) > 512) {
            throw new InvalidBusinessDefinition('A business-definition collection exceeds 512 entries.');
        }
        foreach ($value as $key => $item) {
            if (!is_int($key) && !is_string($key)) {
                throw new InvalidBusinessDefinition('A business-definition key is invalid.');
            }
            self::assertSafe($item, $depth + 1);
        }
    }

    private function __construct()
    {
    }
}
