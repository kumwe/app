<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

/**
 * Where a computed field's value comes from: derived on read, or materialized into a column.
 *
 * Only a field marked computed may leave `Virtual`, and the choice decides more than where work happens.
 * The physical schema compiler reads it to decide whether the field gets a column at all, which in turn
 * decides whether the field can be indexed, made unique, or used to filter, sort, search, and report. The
 * mode travels in the published payload, so switching it is classified as a behaviour change and has to
 * be confirmed before publication.
 *
 * @since  2.0.0
 */
enum ComputationMode: string
{
    /**
     * Evaluated from the record's other values whenever it is read, and given no column of its own.
     *
     * Having no column is what makes a virtual field unable to declare uniqueness, an index, or any
     * query capability: there is nothing for the database to look at.
     *
     * @since  2.0.0
     */
    case Virtual = 'virtual';

    /**
     * Recomputed on write and persisted into its own column, so it can be queried and constrained like
     * an author-supplied field.
     *
     * The price is portability across the supported engines, so the formula must resolve to a portable
     * scalar result type, and a decimal result must additionally declare its precision and scale.
     *
     * @since  2.0.0
     */
    case Stored = 'stored';
}
