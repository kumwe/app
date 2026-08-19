<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Query;

use InvalidArgumentException;

/**
 * Free-text term matched against an explicit allowlist of a definition's fields.
 *
 * Search is a caller convenience that must not become an unbounded scan, so the fields to look in are
 * named rather than discovered: the compiler emits one case-insensitive `LIKE` per named field, ORs
 * them together and ANDs the result with the rest of the query, refusing any field the definition does
 * not declare searchable or that is not stored in a single textual column. The term is length-bounded
 * and its `LIKE` metacharacters are escaped before binding, so a caller cannot widen a search into a
 * wildcard sweep of the table.
 *
 * @since  2.0.0
 */
final readonly class RecordSearch
{
    /**
     * Field handles to look in, deduplicated and sorted so the order they arrived in cannot change the
     * query digest.
     *
     * @var    non-empty-list<string>
     * @since  2.0.0
     */
    public array $fields;

    /**
     * Capture and bound one search request.
     *
     * @param   string                  $term    Text to look for, matched case-insensitively anywhere
     *          inside a field's stored value.
     * @param   non-empty-list<string>  $fields  Handles of the fields to look in; kept deduplicated and
     *          sorted rather than in the order given.
     *
     * @throws  InvalidArgumentException  When the term is blank once trimmed or longer than 256
     *          characters, when no field or more than 16 fields are named, or when a handle is not a
     *          valid query identifier.
     *
     * @since   2.0.0
     */
    public function __construct(public string $term, array $fields)
    {
        if (trim($term) === '' || mb_strlen($term) > 256) {
            throw new InvalidArgumentException('A business-record search term requires 1 to 256 characters.');
        }
        if ($fields === [] || count($fields) > 16) {
            throw new InvalidArgumentException('A business-record search requires between 1 and 16 fields.');
        }
        foreach ($fields as $field) {
            QueryIdentifier::assertField($field);
        }
        $fields = array_values(array_unique($fields));
        sort($fields, SORT_STRING);
        $this->fields = $fields;
    }

    /**
     * Reduce the search to the canonical array the query digest hashes.
     *
     * @return  array{term: string, fields: non-empty-list<string>}  The term exactly as given, with its
     *          fields in sorted order.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['term' => $this->term, 'fields' => $this->fields];
    }
}
