<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

/**
 * One ordering key of a browse, together with where its empty values belong.
 *
 * Ordering decides pagination here, not just presentation: the compiler turns the sorts into a keyset
 * `ORDER BY` and seeks the next page by comparing against the last row's values, with the record
 * identity appended as the final tie-breaker. That is why null placement belongs to the sort instead
 * of being left to the engine — it compiles to an explicit rank expression, so the order is the same
 * on every platform and a cursor issued under it can reproduce it. The field must be declared sortable
 * and back exactly one column the engine can seek on portably, which the compiler enforces when it
 * resolves the handle; a composite field, or one stored as text, JSON or a blob, is refused there.
 *
 * @since  2.0.0
 */
final readonly class RecordSort
{
    /**
     * Capture one ordering key.
     *
     * @param   string         $field      Handle of the field to order by.
     * @param   SortDirection  $direction  Whether that field ascends or descends.
     * @param   bool           $nullsLast  True to rank records with no value after those that have one.
     *
     * @throws  \InvalidArgumentException  When the field handle is not a valid query identifier.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $field,
        public SortDirection $direction = SortDirection::Ascending,
        public bool $nullsLast = true,
    ) {
        QueryIdentifier::assertField($field);
    }

    /**
     * Reduce the sort to the canonical array the query digest hashes.
     *
     * @return  array{field: string, direction: string, nulls_last: bool}  The field handle, the
     *          direction's backing value, and where empty values rank.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'direction' => $this->direction->value, 'nulls_last' => $this->nullsLast];
    }
}
