<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Delivery\Browser;

use InvalidArgumentException;
use Kumwe\App\BusinessReporting\Domain\ReportParameterDefinition;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportValueType;

/**
 * Maps native server-rendered report controls into the strict domain parameter vocabulary.
 *
 * JSON/API callers remain uncoerced. This mapper exists only at the browser delivery boundary, where
 * successful HTML controls arrive as strings. It admits declared names, canonical integer and boolean
 * spellings, decimal strings without floating-point conversion, and bounded one-value-per-line lists.
 * The report domain validates every mapped value again before query compilation.
 *
 * @since  2.0.0
 */
final class ReportParameterInput
{
    /**
     * Convert one native parameter object against its exact policy-visible report declaration.
     *
     * @param   list<ReportParameterDefinition>  $definitions  Parameters from the selected report.
     * @param   array<string, mixed>             $input        Parsed native form object.
     *
     * @return  array<string, mixed>  Typed values for `ReportExecutionRequest` or export request.
     *
     * @throws  InvalidArgumentException  When names, shapes, scalar spellings, or list bounds are invalid.
     *
     * @since   2.0.0
     */
    public static function map(array $definitions, array $input): array
    {
        if ($input !== [] && array_is_list($input)) {
            throw new InvalidArgumentException('Browser report parameters must form an object.');
        }
        $declared = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof ReportParameterDefinition) {
                throw new InvalidArgumentException('Browser report parameter metadata is invalid.');
            }
            $declared[$definition->name] = $definition;
        }
        if (array_diff(array_keys($input), array_keys($declared)) !== []) {
            throw new InvalidArgumentException('A browser report parameter is undeclared.');
        }

        $mapped = [];
        foreach ($input as $name => $value) {
            $definition = $declared[$name];
            if ($value === '') {
                continue;
            }
            if ($definition->multiple) {
                $values = self::list($value);
                if ($values === []) {
                    continue;
                }
                $mapped[$name] = array_map(
                    static fn (string $item): mixed => self::scalar($definition->type, $item),
                    $values,
                );
                continue;
            }
            if (!is_string($value)) {
                throw new InvalidArgumentException('A browser report parameter must be scalar.');
            }
            $mapped[$name] = self::scalar($definition->type, $value);
        }

        return $mapped;
    }

    /**
     * Decode one-per-line browser input without accepting more than the domain list bound.
     *
     * @param   mixed  $value  Textarea value or native repeated-control list.
     *
     * @return  list<string>  Non-empty scalar values in submitted order.
     *
     * @since   2.0.0
     */
    private static function list(mixed $value): array
    {
        $values = is_string($value) ? preg_split('/\R/u', $value, 101) : $value;
        if (!is_array($values) || !array_is_list($values) || count($values) > 100) {
            throw new InvalidArgumentException('A browser report parameter list is invalid or unbounded.');
        }
        $result = [];
        foreach ($values as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException('A browser report parameter list must contain text values.');
            }
            if ($item !== '') {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Map one string without losing decimal or date-time precision.
     *
     * @param   ReportValueType  $type   Declared domain scalar type.
     * @param   string           $value  Native browser string.
     *
     * @return  bool|int|string  Exact domain representation.
     *
     * @since   2.0.0
     */
    private static function scalar(ReportValueType $type, string $value): bool|int|string
    {
        return match ($type) {
            ReportValueType::Boolean => match ($value) {
                '1' => true,
                '0' => false,
                default => throw new InvalidArgumentException('A browser report boolean is invalid.'),
            },
            ReportValueType::Integer => self::integer($value),
            default => $type->accepts($value)
                ? $value
                : throw new InvalidArgumentException('A browser report value contradicts its declared type.'),
        };
    }

    /**
     * Convert a canonical platform integer and reject overflow or repaired spellings.
     *
     * @param   string  $value  Browser integer spelling.
     *
     * @return  int  Exact platform integer.
     *
     * @since   2.0.0
     */
    private static function integer(string $value): int
    {
        if (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1 || strlen($value) > 20) {
            throw new InvalidArgumentException('A browser report integer is invalid.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer) || (string) $integer !== $value) {
            throw new InvalidArgumentException('A browser report integer is outside the platform range.');
        }

        return $integer;
    }
}
