<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

final class ExpressionEvaluator
{
    /** @param array<string, scalar|null> $fields */
    public static function evaluate(Expression $expression, array $fields): mixed
    {
        if ($expression->operator === 'literal') {
            return $expression->literal;
        }
        if ($expression->operator === 'field') {
            if ($expression->field === null || !array_key_exists($expression->field, $fields)) {
                throw new InvalidBusinessDefinition('A formula dependency is unavailable.');
            }
            $value = $fields[$expression->field];
            if (is_float($value)) {
                throw new InvalidBusinessDefinition('Formula inputs cannot contain PHP floats.');
            }

            return $value;
        }
        $values = array_map(
            static fn (Expression $item): mixed => self::evaluate($item, $fields),
            $expression->arguments(),
        );

        return match ($expression->operator) {
            'eq' => $values[0] === $values[1],
            'ne' => $values[0] !== $values[1],
            'lt' => self::compare($expression->arguments()[0]->type, $values[0], $values[1]) < 0,
            'lte' => self::compare($expression->arguments()[0]->type, $values[0], $values[1]) <= 0,
            'gt' => self::compare($expression->arguments()[0]->type, $values[0], $values[1]) > 0,
            'gte' => self::compare($expression->arguments()[0]->type, $values[0], $values[1]) >= 0,
            'and' => !in_array(false, $values, true),
            'or' => in_array(true, $values, true),
            'not' => !self::boolean($values[0]),
            'add' => self::arithmetic($expression->type, 'add', $values),
            'subtract' => self::arithmetic($expression->type, 'subtract', $values),
            'multiply' => self::arithmetic($expression->type, 'multiply', $values),
            'divide' => self::divide($expression->type, $values, $expression->scale),
            'concat' => implode('', array_map(self::string(...), $values)),
            'coalesce' => self::coalesce($values),
            'if' => self::boolean($values[0]) ? $values[1] : $values[2],
            'is_null' => $values[0] === null,
            'in' => in_array($values[0], array_slice($values, 1), true),
            'contains' => str_contains(self::string($values[0]), self::string($values[1])),
            default => throw new InvalidBusinessDefinition('A formula operator is not executable.'),
        };
    }

    private static function compare(string $type, mixed $left, mixed $right): int
    {
        if ($type === 'decimal') {
            return DecimalValue::fromString(self::string($left))->compare(DecimalValue::fromString(self::string($right)));
        }
        if (is_int($left) && is_int($right)) {
            return $left <=> $right;
        }
        if (is_string($left) && is_string($right)) {
            return $left <=> $right;
        }
        throw new InvalidBusinessDefinition('Formula comparison operands are incompatible.');
    }

    /** @param list<mixed> $values */
    private static function arithmetic(string $type, string $operation, array $values): int|string
    {
        if ($type === 'integer') {
            $result = self::integer(array_shift($values));
            foreach ($values as $value) {
                $operand = self::integer($value);
                $result = match ($operation) {
                    'add' => $result + $operand,
                    'subtract' => $result - $operand,
                    'multiply' => $result * $operand,
                    default => throw new InvalidBusinessDefinition('The integer formula operation is unsupported.'),
                };
            }

            return $result;
        }
        $result = DecimalValue::fromString(self::string(array_shift($values)));
        foreach ($values as $value) {
            $operand = DecimalValue::fromString(self::string($value));
            $result = match ($operation) {
                'add' => $result->add($operand),
                'subtract' => $result->subtract($operand),
                'multiply' => $result->multiply($operand),
                default => throw new InvalidBusinessDefinition('The decimal formula operation is unsupported.'),
            };
        }

        return $result->value();
    }

    /** @param list<mixed> $values */
    private static function divide(string $type, array $values, ?int $scale): int|string
    {
        if ($type === 'integer') {
            $left = self::integer($values[0]);
            $right = self::integer($values[1]);
            if ($right === 0) {
                throw new InvalidBusinessDefinition('A formula attempted division by zero.');
            }

            return intdiv($left, $right);
        }

        return DecimalValue::fromString(self::string($values[0]))
            ->divide(DecimalValue::fromString(self::string($values[1])), $scale ?? 0)
            ->value();
    }

    private static function boolean(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new InvalidBusinessDefinition('A formula expected a boolean value.');
        }

        return $value;
    }

    private static function integer(mixed $value): int
    {
        if (!is_int($value)) {
            throw new InvalidBusinessDefinition('A formula expected an integer value.');
        }

        return $value;
    }

    private static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidBusinessDefinition('A formula expected a string value.');
        }

        return $value;
    }

    /** @param list<mixed> $values */
    private static function coalesce(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function __construct()
    {
    }
}
