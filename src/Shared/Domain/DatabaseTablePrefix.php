<?php

declare(strict_types=1);

namespace Kumwe\CMS\Shared\Domain;

/**
 * Rule that decides whether a configured table prefix is safe to interpolate into SQL.
 *
 * Kumwe composes physical table names by concatenating this prefix with a name it controls, so the
 * prefix is the one part of an identifier that comes from configuration. Validating it up front — at
 * configuration load, at `TableNames` construction, and in the physical name compiler — keeps the
 * concatenation free of quoting decisions and keeps operator input out of the SQL grammar.
 *
 * @since  2.0.0
 */
final class DatabaseTablePrefix
{
    /**
     * Longest prefix accepted, leaving room for the longest generated name within engine identifier limits.
     *
     * @var    int
     * @since  2.0.0
     */
    public const MAXIMUM_BYTES = 28;

    /**
     * Decide whether a prefix may be used to build physical table names.
     *
     * A valid prefix is lowercase, starts with a letter, joins alphanumeric groups with single
     * underscores, ends with a trailing underscore, and stays within the length budget.
     *
     * @param   string  $prefix  Candidate prefix as supplied by configuration.
     *
     * @return  bool  True when the prefix is safe to concatenate into an identifier.
     *
     * @since   2.0.0
     */
    public static function isValid(string $prefix): bool
    {
        return strlen($prefix) <= self::MAXIMUM_BYTES
            && preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*_$/D', $prefix) === 1;
    }
}
