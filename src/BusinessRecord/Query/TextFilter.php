<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Query;

use InvalidArgumentException;

/**
 * Leaf of a business-record filter tree that matches one named textual field against a substring.
 *
 * Reach for this when the caller knows which field to look in and how the text should anchor; a term to
 * be looked for across several fields at once is `RecordSearch` instead. The match is deliberately
 * narrow: the compiler renders it as `LOWER(column) LIKE ? ESCAPE '!'` over a single string, text or
 * ascii-string column, having escaped `!`, `%` and `_` in the caller's text first. Matching is therefore
 * case-insensitive, the operator alone decides where the wildcards sit, and a caller cannot widen a
 * lookup into a wildcard sweep by putting metacharacters in the search text.
 *
 * @since  2.0.0
 */
final readonly class TextFilter implements RecordFilter
{
    /**
     * Match one field against a substring, proving the field handle and bounding the text first.
     *
     * @param   string        $field     Handle of the definition field to test; the compiler further requires
     *          it to be filterable, visible to the caller, and stored in exactly one textual column.
     * @param   TextOperator  $operator  Where in the stored value the text has to sit.
     * @param   string        $text      Text to look for, 1 to 512 characters, matched case-insensitively and
     *          taken literally: its `LIKE` metacharacters are escaped before binding.
     *
     * @throws  InvalidArgumentException  When the field handle is not a lowercase query identifier, or the
     *          text is empty or longer than 512 characters.
     *
     * @since   2.0.0
     */
    public function __construct(public string $field, public TextOperator $operator, public string $text)
    {
        QueryIdentifier::assertField($field);
        if ($text === '' || mb_strlen($text) > 512) {
            throw new InvalidArgumentException('A text filter requires between 1 and 512 characters.');
        }
    }

    /**
     * Export the match in the canonical shape a query digest hashes.
     *
     * The text is carried through exactly as the caller wrote it, case included, so two queries differing
     * only in the casing of their search text fingerprint differently even though they match the same rows.
     *
     * @return  array{type: string, field: string, operator: string, text: string}  The node tagged `text`,
     *          the field handle, the operator's backing value, and the unescaped search text.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['type' => 'text', 'field' => $this->field, 'operator' => $this->operator->value,
            'text' => $this->text];
    }

    /**
     * Count the predicates a compiler would emit for this node.
     *
     * @return  int  Always one: the match becomes a single `LIKE` comparison.
     *
     * @since   2.0.0
     */
    public function operationCount(): int
    {
        return 1;
    }

    /**
     * Measure how far this node nests.
     *
     * @return  int  Always one: a text match is a leaf and carries no children.
     *
     * @since   2.0.0
     */
    public function depth(): int
    {
        return 1;
    }

    /**
     * Measure how many relation hops this node crosses.
     *
     * @return  int  Always zero: a text match reads the queried record's own field.
     *
     * @since   2.0.0
     */
    public function relationDepth(): int
    {
        return 0;
    }
}
