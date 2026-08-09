<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

/**
 * Interpreter that turns a parsed `Expression` into a value without ever letting a PHP float appear.
 *
 * Everything that would make execution ambiguous has already been settled by `Expression::fromArray()`,
 * so this class assumes a well typed tree and concerns itself only with running it: it resolves `field`
 * nodes against the values the caller supplied, holds each of those values to the type its node declares,
 * and routes `decimal` arithmetic through `DecimalValue` so a formula produces the same digits in every
 * process and on every engine. Integer arithmetic is checked for overflow instead of being promoted to
 * float behind the caller's back, and an absent dependency is refused rather than read as null, so a
 * record invariant is never quietly reported as satisfied on incomplete input. Callers reach it through
 * `Expression::evaluate()`; it is a stateless collection of static routines and cannot be instantiated.
 *
 * @since  2.0.0
 */
final class ExpressionEvaluator
{
    /**
     * Evaluate an expression tree against one set of field values.
     *
     * Arguments are evaluated eagerly, before the operator runs, so `and`, `or`, `if`, and `coalesce` do
     * not short-circuit: a branch that fails fails the whole expression even when its value would have
     * been discarded. Callers that need a guarded division must therefore make the guard part of the
     * data, not of the tree shape.
     *
     * @param   Expression                  $expression  Node to evaluate; its subtree is walked recursively.
     * @param   array<string, scalar|null>  $fields      Values for the handles the tree reads, keyed by
     *          field handle.
     *
     * @return  mixed  Result of the operator in the node's declared type; a decimal result is a canonical
     *          base-10 string.
     *
     * @throws  InvalidBusinessDefinition  When a dependency is absent, a supplied value is a float or
     *          contradicts its declared type, an operand has the wrong runtime type, or the arithmetic
     *          overflows or divides by zero.
     *
     * @since   2.0.0
     */
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

            return self::typed($expression->type, $value);
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

    /**
     * Order the two operands of `lt`, `lte`, `gt`, or `gte`.
     *
     * Decimals go through `DecimalValue`, so `'1.10'` and `'1.1'` order as equal instead of comparing as
     * text. Every other ordered type falls to PHP's own comparison, which means a `date`, `time`, or
     * `datetime` is ordered lexicographically on the string it arrived as.
     *
     * @param   string  $type   Type the left argument declares, which parsing has matched to the right.
     * @param   mixed   $left   Evaluated value on the left of the comparison.
     * @param   mixed   $right  Evaluated value on the right of the comparison.
     *
     * @return  int  Negative when the left value orders first, zero when the two are equal, positive
     *          when the left value orders last.
     *
     * @throws  InvalidBusinessDefinition  When a decimal operand is not a canonical decimal string, or
     *          the operands are neither two integers nor two strings.
     *
     * @since   2.0.0
     */
    private static function compare(string $type, mixed $left, mixed $right): int
    {
        if ($type === 'decimal') {
            return DecimalValue::fromString(self::string($left))->compare(
                DecimalValue::fromString(self::string($right)),
            );
        }
        if (is_int($left) && is_int($right)) {
            return $left <=> $right;
        }
        if (is_string($left) && is_string($right)) {
            return $left <=> $right;
        }
        throw new InvalidBusinessDefinition('Formula comparison operands are incompatible.');
    }

    /**
     * Fold `add`, `subtract`, or `multiply` across the operands of an integer or decimal node.
     *
     * The integer branch re-checks the result after every single step, because PHP promotes an
     * overflowing integer operation to float rather than failing, and the check has to catch that before
     * the float reaches the next step. The decimal branch accumulates through `DecimalValue` instead, so
     * no float is produced at any point.
     *
     * @param   string       $type       Result type the node declares, either `integer` or `decimal`.
     * @param   string       $operation  Fold to apply: `add`, `subtract`, or `multiply`.
     * @param   list<mixed>  $values     Evaluated operands, folded left to right from the first.
     *
     * @return  int|string  An `int` for an integer node, a canonical decimal string for a decimal node.
     *
     * @throws  InvalidBusinessDefinition  When an operand has the wrong runtime type, the operation is
     *          not one of the three folds, an integer result leaves the platform integer range, or a
     *          decimal result needs more digits than `DecimalValue` allows.
     *
     * @since   2.0.0
     */
    private static function arithmetic(string $type, string $operation, array $values): int|string
    {
        if ($type === 'integer') {
            $result = self::integer(array_shift($values));
            foreach ($values as $value) {
                $operand = self::integer($value);
                $next = match ($operation) {
                    'add' => $result + $operand,
                    'subtract' => $result - $operand,
                    'multiply' => $result * $operand,
                    default => throw new InvalidBusinessDefinition('The integer formula operation is unsupported.'),
                };
                // PHP promotes overflowing integer arithmetic to float at runtime.
                /** @phpstan-ignore function.alreadyNarrowedType */
                if (!is_int($next)) {
                    throw new InvalidBusinessDefinition('An integer formula exceeded the platform integer range.');
                }
                $result = $next;
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

    /**
     * Divide the dividend and divisor of a `divide` node.
     *
     * Integer division truncates toward zero through `intdiv()`, and the one integer pair whose quotient
     * does not fit — `PHP_INT_MIN` over `-1` — is refused rather than allowed to wrap. Decimal division
     * rounds to the scale the node declares, which parsing requires a decimal division to carry.
     *
     * @param   string       $type    Result type the node declares, either `integer` or `decimal`.
     * @param   list<mixed>  $values  Evaluated dividend and divisor, in that order.
     * @param   ?int         $scale   Decimal places to round the quotient to; null for an integer node.
     *
     * @return  int|string  An `int` for an integer node, a canonical decimal string for a decimal node.
     *
     * @throws  InvalidBusinessDefinition  When an operand has the wrong runtime type, the divisor is
     *          zero, or the quotient leaves the platform integer range.
     *
     * @since   2.0.0
     */
    private static function divide(string $type, array $values, ?int $scale): int|string
    {
        if ($type === 'integer') {
            $left = self::integer($values[0]);
            $right = self::integer($values[1]);
            if ($right === 0) {
                throw new InvalidBusinessDefinition('A formula attempted division by zero.');
            }
            if ($left === PHP_INT_MIN && $right === -1) {
                throw new InvalidBusinessDefinition('An integer formula exceeded the platform integer range.');
            }

            return intdiv($left, $right);
        }

        return DecimalValue::fromString(self::string($values[0]))
            ->divide(DecimalValue::fromString(self::string($values[1])), $scale ?? 0)
            ->value();
    }

    /**
     * Read an evaluated operand that the operator requires to be a boolean.
     *
     * @param   mixed  $value  Evaluated operand to check.
     *
     * @return  bool  The operand unchanged.
     *
     * @throws  InvalidBusinessDefinition  When the operand is not a boolean.
     *
     * @since   2.0.0
     */
    private static function boolean(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new InvalidBusinessDefinition('A formula expected a boolean value.');
        }

        return $value;
    }

    /**
     * Read an evaluated operand that the operator requires to be an integer.
     *
     * @param   mixed  $value  Evaluated operand to check.
     *
     * @return  int  The operand unchanged.
     *
     * @throws  InvalidBusinessDefinition  When the operand is not an integer.
     *
     * @since   2.0.0
     */
    private static function integer(mixed $value): int
    {
        if (!is_int($value)) {
            throw new InvalidBusinessDefinition('A formula expected an integer value.');
        }

        return $value;
    }

    /**
     * Read an evaluated operand that the operator requires to be a string.
     *
     * Also the gate in front of `DecimalValue`, since a decimal operand travels as its canonical text.
     *
     * @param   mixed  $value  Evaluated operand to check.
     *
     * @return  string  The operand unchanged.
     *
     * @throws  InvalidBusinessDefinition  When the operand is not a string.
     *
     * @since   2.0.0
     */
    private static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidBusinessDefinition('A formula expected a string value.');
        }

        return $value;
    }

    /**
     * Hold a caller-supplied field value to the type its `field` node declares.
     *
     * This is the boundary where record data enters evaluation, so the declared type is enforced here
     * rather than assumed: a `decimal` field has to arrive as a canonical base-10 string, and `any`
     * admits only null, boolean, integer, and string. Unlike a literal, a field value carries no length
     * ceiling at this point.
     *
     * @param   string  $type   Type the `field` node declares for the value.
     * @param   mixed   $value  Value the caller supplied for that field.
     *
     * @return  mixed  The value unchanged, once it matches the declared type.
     *
     * @throws  InvalidBusinessDefinition  When the value does not match the declared type.
     *
     * @since   2.0.0
     */
    private static function typed(string $type, mixed $value): mixed
    {
        $valid = match ($type) {
            'any' => is_null($value) || is_bool($value) || is_int($value) || is_string($value),
            'null' => $value === null,
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'decimal' => is_string($value)
                && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) === 1,
            'string', 'date', 'time', 'datetime' => is_string($value),
            default => false,
        };
        if (!$valid) {
            throw new InvalidBusinessDefinition('A formula field value does not match its declared type.');
        }

        return $value;
    }

    /**
     * Return the first operand that is not null, the way SQL `COALESCE` does.
     *
     * @param   list<mixed>  $values  Evaluated operands in declaration order.
     *
     * @return  mixed  The first non-null operand, or null when every operand is null.
     *
     * @since   2.0.0
     */
    private static function coalesce(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Block instantiation; every member of this evaluator is static.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
