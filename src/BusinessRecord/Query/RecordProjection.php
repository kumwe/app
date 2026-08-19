<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Query;

use InvalidArgumentException;

/**
 * Choice of what a browse page carries back: field values, hydrated relations, and aggregates.
 *
 * A stored record is usually wider than the caller needs, so the projection is how a caller names the
 * part it wants and nothing else. Empty `fields` means every field the definition exposes to readers,
 * which is the common case; naming fields narrows both the columns the compiler selects and the view
 * the caller receives, and the compiler adds back any field a requested formula depends on. `includes`
 * are resolved once for the whole page rather than per row, and `aggregates` run over every matching
 * record rather than over the page, so neither cost grows with the page size. The three counts are
 * capped here, at construction, because they are what decide how much work one page of a browse does.
 *
 * @since  2.0.0
 */
final readonly class RecordProjection
{
    /**
     * Field handles the page decodes and discloses, deduplicated; empty selects every readable field.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $fields;

    /**
     * Relationship handles hydrated for the whole page, deduplicated; empty returns bare records.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $includes;

    /**
     * Aggregates computed over every matching record, each under its own alias; empty computes none.
     *
     * @var    list<RecordAggregate>
     * @since  2.0.0
     */
    public array $aggregates;

    /**
     * Capture and bound what a browse page should carry back.
     *
     * Field and include handles are checked as query identifiers and deduplicated here; whether the
     * definition actually declares them, and whether the caller may read them, is settled later by the
     * compiler against the pinned definition.
     *
     * @param   list<string>           $fields      Field handles to disclose, or empty for every readable field.
     * @param   list<string>           $includes    Relationship handles to hydrate alongside the page.
     * @param   list<RecordAggregate>  $aggregates  Aggregates to compute over the whole match.
     *
     * @throws  InvalidArgumentException  When more than 64 fields, 4 includes or 16 aggregates are
     *          asked for, a field or include handle is not a valid query identifier, or two aggregates
     *          claim the same alias.
     *
     * @since   2.0.0
     */
    public function __construct(array $fields = [], array $includes = [], array $aggregates = [])
    {
        if (count($fields) > 64 || count($includes) > 4 || count($aggregates) > 16) {
            throw new InvalidArgumentException(
                'A business-record projection exceeds its field, include, or aggregate limit.',
            );
        }
        foreach (array_merge($fields, $includes) as $identifier) {
            QueryIdentifier::assertField($identifier);
        }
        $aliases = [];
        foreach ($aggregates as $aggregate) {
            if (isset($aliases[$aggregate->alias])) {
                throw new InvalidArgumentException('A business-record aggregate alias is duplicated.');
            }
            $aliases[$aggregate->alias] = true;
        }
        $this->fields = array_values(array_unique($fields));
        $this->includes = array_values(array_unique($includes));
        $this->aggregates = array_values($aggregates);
    }

    /**
     * Reduce the projection to the canonical array the query digest hashes.
     *
     * @return  array{fields: list<string>, includes: list<string>, aggregates: list<array<string, mixed>>}
     *          The three requested lists, with each aggregate already flattened to its own array form.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'fields' => $this->fields,
            'includes' => $this->includes,
            'aggregates' => array_map(static fn (RecordAggregate $item): array => $item->toArray(), $this->aggregates),
        ];
    }
}
