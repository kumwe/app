<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

/**
 * What deleting the record on the target side of a relationship does to the association.
 *
 * Which behaviour a relationship may declare is deliberately narrow: `Cascade` belongs to owned line
 * collections alone, `SetNull` to optional singular associations, and everything else keeps `Restrict`,
 * because an unqualified cascade between independent entities would remove records nobody asked about.
 * The one place the declared value reaches SQL directly is the target foreign key of a junction table.
 * A singular association lives in a column on the record table, and there the physical key is pinned to
 * `RESTRICT` whatever was declared, so `BusinessRecordService` can do the clearing itself and leave each
 * affected source record with a version bump, a revision, and audit evidence rather than have rows change
 * underneath the runtime.
 *
 * @since  2.0.0
 */
enum DeleteBehavior: string
{
    /**
     * Refuse to delete a target while any record still points at it.
     *
     * The default, and what a relationship keeps unless it qualifies for one of the other two.
     *
     * @since  2.0.0
     */
    case Restrict = 'restrict';
    /**
     * Remove the dependent rows along with the target they hang from.
     *
     * Reserved for an owned line collection, whose lines have no existence apart from their owner and
     * whose line table already cascades from it structurally. The graph validator refuses this on every
     * other kind, and the record runtime refuses a delete whose cascade would reach an independent
     * entity without an explicit bounded delete workflow.
     *
     * @since  2.0.0
     */
    case Cascade = 'cascade';
    /**
     * Clear the association and leave the record that held it standing.
     *
     * Only an optional singular relationship may declare it: there has to be one column to clear, and a
     * required association cannot be nulled away and still satisfy its own contract.
     *
     * @since  2.0.0
     */
    case SetNull = 'set_null';
}
