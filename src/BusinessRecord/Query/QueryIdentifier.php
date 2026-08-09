<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use InvalidArgumentException;

/**
 * The grammar every caller-supplied name in a business-record query has to satisfy.
 *
 * Filter fields, sort fields, projection entries and relationship names all arrive from outside, and
 * the compiler resolves each to a physical name it concatenates into SQL. Applying the grammar in each
 * query node's constructor means a name carrying a quote, a wildcard, whitespace or upper case is
 * refused where it is introduced, so a whole filter tree is valid by construction and no later pass has
 * to be trusted to sweep it.
 *
 * @since  2.0.0
 */
final class QueryIdentifier
{
    /**
     * Require a caller-supplied name to be a well-formed query identifier.
     *
     * @param   string  $value  Candidate name: a filter or sort field, a projection entry, or the
     *          relationship a relation filter traverses.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is not one to 63 characters of lowercase letters,
     *          digits and underscores beginning with a letter.
     *
     * @since   2.0.0
     */
    public static function assertField(string $value): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $value) !== 1) {
            throw new InvalidArgumentException('A business-record query identifier is invalid.');
        }
    }

    /**
     * Prevent instantiation; the grammar is a static rule with no state.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
