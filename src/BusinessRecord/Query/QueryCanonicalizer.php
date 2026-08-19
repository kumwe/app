<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Query;

use Kumwe\App\BusinessRecord\Domain\RecordValueGuard;

/**
 * Reduces a query literal to the stable form a specification digest and a cursor payload are built on.
 *
 * Every filter node canonicalises its literals as it exports itself, which is what makes a digest
 * depend on the value the query means rather than on the PHP type the caller happened to hand over: an
 * `ExactDecimal` and its decimal string, or a `DateTimeImmutable` and its ISO-8601 rendering, collapse
 * to the same text, so two callers expressing the same page get the same digest and can share a
 * cursor. The reduction itself is `RecordValueGuard`'s; this type exists so the query layer depends on
 * one narrow entry point rather than on the record domain's wider guard.
 *
 * @since  2.0.0
 */
final class QueryCanonicalizer
{
    /**
     * Reduce one query literal to its canonical, JSON-encodable form.
     *
     * @param   mixed  $value  Literal held by a filter, a set member, or a cursor sort value.
     *
     * @return  mixed  What the value canonicalises to: a decimal flattens to its string, money,
     *          quantity and zoned date-time to their stored arrays, a date to an ISO-8601 string with
     *          microseconds and offset, and null, bools, ints and strings pass through untouched.
     *
     * @throws  \InvalidArgumentException  When the value, or anything nested inside an array, is a float
     *          or a runtime type a business record cannot carry.
     *
     * @since   2.0.0
     */
    public static function value(mixed $value): mixed
    {
        return RecordValueGuard::canonical($value);
    }

    /**
     * Prevent instantiation; the canonical form is a static rule with no state.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
