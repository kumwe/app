<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use InvalidArgumentException;

/**
 * One summary column a report query asks the database to compute over the records a filter selects.
 *
 * A projection carries a list of these, and each names the alias its result comes back under together
 * with the function and, for every function but `Count`, the field to compute it over. The constructor
 * settles the part of the pairing that needs no definition — a count takes no field, everything else
 * requires one — so an inconsistent aggregate cannot be built at all. The rest stays with
 * `DoctrineBusinessRecordQueryCompiler`, which resolves the handle and additionally demands that the
 * field be reportable, visible to the caller, and stored in a single column of a type the chosen
 * function answers exactly.
 *
 * @since  2.0.0
 */
final readonly class RecordAggregate
{
    /**
     * Declare one aggregate, fixing its alias and its function-to-field pairing up front.
     *
     * @param   string             $alias     Output key the computed value is returned under; unique
     *          within a projection, which `RecordProjection` enforces across the list.
     * @param   AggregateFunction  $function  Summary to compute over the matching records.
     * @param   ?string            $field     Handle of the field to compute over, or null for a count,
     *          which is measured over rows rather than over a nominated field.
     *
     * @throws  InvalidArgumentException  When the alias is not one to 63 characters of lowercase letters,
     *          digits and underscores beginning with a letter, when a field is given for a count or left
     *          out of any other function, or when the field handle is not a valid query identifier.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $alias,
        public AggregateFunction $function,
        public ?string $field = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $alias) !== 1) {
            throw new InvalidArgumentException('A record aggregate alias is invalid.');
        }
        if (($function === AggregateFunction::Count) !== ($field === null)) {
            throw new InvalidArgumentException('Only count aggregates omit a field.');
        }
        if ($field !== null) {
            QueryIdentifier::assertField($field);
        }
    }

    /**
     * Export the aggregate in the canonical shape a query digest hashes.
     *
     * @return  array{alias: string, function: string, field: ?string}  The output alias, the function's
     *          backing value, and the field handle, which is null exactly for a count.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['alias' => $this->alias, 'function' => $this->function->value, 'field' => $this->field];
    }
}
