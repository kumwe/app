<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Application\Custom;

use InvalidArgumentException;
use JsonException;

/**
 * Enforces the transport-neutral structural budget shared by custom business inputs and results.
 *
 * Custom handlers receive decoded values rather than transport objects, but decoded data is still
 * untrusted. This guard admits only exact JSON values, rejects floats and runtime objects, and caps
 * nesting, nodes, collection widths, strings, and encoded bytes before extension code sees them.
 *
 * @since  2.0.0
 */
final class CustomBusinessPayload
{
    /**
     * Maximum encoded size of one custom-handler input or result.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_BYTES = 262_144;

    /**
     * Assert a payload is a bounded JSON object with portable property names.
     *
     * @param   array<string, mixed>  $payload  Decoded input or result map to admit.
     * @param   string                $kind     Human-readable payload kind used in failures.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the payload is list-shaped, structurally oversized,
     *          contains an unsafe property name, float, object, resource, invalid UTF-8, or unsupported value.
     *
     * @since   2.0.0
     */
    public static function assertObject(array $payload, string $kind): void
    {
        if ($payload !== [] && array_is_list($payload)) {
            throw new InvalidArgumentException(sprintf('A custom business %s must be an object.', $kind));
        }

        $nodes = 0;
        self::assertValue($payload, $kind, 0, $nodes);

        try {
            $bytes = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                sprintf('A custom business %s must contain valid UTF-8 JSON values.', $kind),
                0,
                $exception,
            );
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException(sprintf('A custom business %s exceeds 262144 bytes.', $kind));
        }
    }

    /**
     * Walk one decoded value under the shared depth, node, collection, and string budgets.
     *
     * @param   mixed   $value  Value at the current location.
     * @param   string  $kind   Payload kind used in failures.
     * @param   int     $depth  Current nesting depth, starting at zero.
     * @param   int     $nodes  Shared number of values visited so far.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value falls outside the bounded exact JSON subset.
     *
     * @since   2.0.0
     */
    private static function assertValue(mixed $value, string $kind, int $depth, int &$nodes): void
    {
        ++$nodes;
        if ($depth > 8 || $nodes > 4096) {
            throw new InvalidArgumentException(sprintf(
                'A custom business %s exceeds its depth or node budget.',
                $kind,
            ));
        }
        if (is_string($value)) {
            if (strlen($value) > 65_535) {
                throw new InvalidArgumentException(sprintf(
                    'A custom business %s contains an oversized string.',
                    $kind,
                ));
            }
            return;
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf(
                'A custom business %s may contain only exact JSON values.',
                $kind,
            ));
        }

        $limit = array_is_list($value) ? 200 : 128;
        if (count($value) > $limit) {
            throw new InvalidArgumentException(sprintf(
                'A custom business %s contains an unbounded collection.',
                $kind,
            ));
        }
        foreach ($value as $key => $item) {
            if (
                !array_is_list($value)
                && (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $key) !== 1)
            ) {
                throw new InvalidArgumentException(sprintf(
                    'A custom business %s contains an unsafe property name.',
                    $kind,
                ));
            }
            self::assertValue($item, $kind, $depth + 1, $nodes);
        }
    }

    /**
     * Prevent instantiation; payload admission is a stateless boundary.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
