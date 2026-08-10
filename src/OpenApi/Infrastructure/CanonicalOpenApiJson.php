<?php

declare(strict_types=1);

namespace Kumwe\CMS\OpenApi\Infrastructure;

use JsonException;

/**
 * Encodes OpenAPI documents with recursively sorted object keys and stable whitespace.
 *
 * @since  2.0.0
 */
final class CanonicalOpenApiJson
{
    /**
     * Encode one document into deterministic UTF-8 JSON bytes.
     *
     * JSON arrays retain order because order can be meaningful for examples and parameter precedence;
     * object keys are sorted recursively so registry insertion order cannot change a checksum.
     *
     * @param   array<string, mixed>  $document  OpenAPI document.
     *
     * @return  string  Canonical pretty-printed bytes ending in one newline.
     *
     * @throws  JsonException  When a value cannot be encoded.
     *
     * @since   2.0.0
     */
    public static function encode(array $document): string
    {
        return json_encode(
            self::sort($document),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        ) . "\n";
    }

    /**
     * Recursively sort object keys while retaining array order.
     *
     * @param   mixed  $value  JSON-compatible value.
     *
     * @return  mixed  Canonically ordered value.
     *
     * @since   2.0.0
     */
    private static function sort(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sort(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $member) {
            $value[$key] = self::sort($member);
        }

        return $value;
    }

    /**
     * Prevent construction; canonical encoding is stateless.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
