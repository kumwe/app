<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

/**
 * Cardinality of a declared relationship, and with it the physical shape the schema compiler emits.
 *
 * The kind decides three things at once: whether the association lives in a column on the owning record
 * table or in a table of its own, which kind a reciprocal `inverse` must declare, and which delete
 * behaviour and ordering the definition may ask for. A pair that names each other as inverses is
 * materialized once only: the many-to-one side carries the storage its one-to-many partner reads back,
 * and a symmetric pair is settled by comparing the two handles.
 *
 * @since  2.0.0
 */
enum RelationshipKind: string
{
    /**
     * At most one record on each side, stored as a uniquely indexed target column on this entity.
     *
     * @since  2.0.0
     */
    case OneToOne = 'one_to_one';

    /**
     * Many records of this entity point at one target, stored as a target column on this entity.
     *
     * This is the side that carries the storage when it is paired with a `OneToMany` inverse.
     *
     * @since  2.0.0
     */
    case ManyToOne = 'many_to_one';

    /**
     * A collection of targets that belong to this record alone, kept in a junction table.
     *
     * The junction's target index is unique, so a target cannot appear under two owners. Paired with a
     * `ManyToOne` inverse it emits nothing of its own and reads the column that side already holds.
     *
     * @since  2.0.0
     */
    case OneToMany = 'one_to_many';

    /**
     * A collection on both sides, kept in a junction table whose rows are the pairs themselves.
     *
     * Reciprocal `ManyToMany` relationships must agree on ordering, since one shared table serves both.
     *
     * @since  2.0.0
     */
    case ManyToMany = 'many_to_many';

    /**
     * Lines owned outright by this record, kept in a line table and deleted with their owner.
     *
     * Ownership is exclusive and structural: the delete behaviour must be `Cascade`, the kind takes no
     * inverse, and the validator refuses a cycle of owned relationships.
     *
     * @since  2.0.0
     */
    case OwnedLineCollection = 'owned_line_collection';

    /**
     * A typed link from a correcting record to the record of the same definition that it reverses.
     *
     * This is how an immutable document is corrected: the original is never rewritten, a new record of
     * the same definition carries this link back to it, and both remain readable. Storage is exactly a
     * `ManyToOne` column on the correcting record's own table, its inverse — "what corrected this" — is
     * a `OneToMany` on the same definition, and deletion of the reversed record must be restricted so
     * the pair can never be silently unpaired. The kind is core vocabulary so every installation can ask
     * "what did this correct, and what corrected this" as a declared relationship rather than through a
     * per-vertical field name.
     *
     * @since  2.0.0
     */
    case Reversal = 'reversal';
}
