<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Domain;

/**
 * Portable vocabulary of semantic changes a schema plan may contain.
 *
 * A plan never persists executable SQL: it persists one of these kinds plus the before/after blueprint
 * state, and the physical gateway decides what statements the current driver needs. That indirection is
 * what lets an administrator approve an exact plan, lets the executor journal and replay each step, and
 * lets live introspection confirm afterwards that the intended change actually landed.
 *
 * @since  2.0.0
 */
enum SchemaOperationKind: string
{
    /**
     * Creates a table that the target blueprint declares and the installed schema does not have.
     *
     * @since  2.0.0
     */
    case CreateTable = 'create_table';

    /**
     * Moves an existing table to the physical name its target blueprint declares.
     *
     * @since  2.0.0
     */
    case RenameTable = 'rename_table';

    /**
     * Removes a table the target blueprint no longer declares, destroying its rows.
     *
     * @since  2.0.0
     */
    case DropTable = 'drop_table';

    /**
     * Adds a column the target blueprint declares to an existing table.
     *
     * @since  2.0.0
     */
    case AddColumn = 'add_column';

    /**
     * Changes an existing column's type, nullability, or portable options in place.
     *
     * @since  2.0.0
     */
    case AlterColumn = 'alter_column';

    /**
     * Moves an existing column to its new physical name, keeping the stored values.
     *
     * @since  2.0.0
     */
    case RenameColumn = 'rename_column';

    /**
     * Removes a column the target blueprint no longer declares, destroying its values.
     *
     * @since  2.0.0
     */
    case DropColumn = 'drop_column';

    /**
     * Installs the primary-key constraint the target table blueprint declares.
     *
     * @since  2.0.0
     */
    case AddPrimaryKey = 'add_primary_key';

    /**
     * Removes the existing primary-key constraint, usually to re-key a table.
     *
     * @since  2.0.0
     */
    case DropPrimaryKey = 'drop_primary_key';

    /**
     * Creates an index or unique constraint the target blueprint declares.
     *
     * @since  2.0.0
     */
    case AddIndex = 'add_index';

    /**
     * Removes an index the target blueprint no longer declares.
     *
     * @since  2.0.0
     */
    case DropIndex = 'drop_index';

    /**
     * Creates a referential constraint between two installed tables.
     *
     * @since  2.0.0
     */
    case AddForeignKey = 'add_foreign_key';

    /**
     * Removes a referential constraint, typically to let a column rewrite proceed.
     *
     * @since  2.0.0
     */
    case DropForeignKey = 'drop_foreign_key';

    /**
     * Writes the declared literal or bounded expression into rows that predate a column.
     *
     * This is a data rewrite, not DDL: the executor runs it in bounded chunks under the plan's fence.
     *
     * @since  2.0.0
     */
    case Backfill = 'backfill';

    /**
     * Rewrites existing column values through the bounded expression a definition declares.
     *
     * Like a backfill this runs in chunks, and it is never treated as already satisfied by introspection,
     * because no shape of the live table proves the values were converted.
     *
     * @since  2.0.0
     */
    case Transform = 'transform';

    /**
     * Advances stored rows from their older pinned definition version to the newly published one.
     *
     * Each row is decoded, revalidated, and recomputed against the target mapping before its pin moves,
     * which is what allows a rename or type rewrite to be applied over historical rows at all.
     *
     * @since  2.0.0
     */
    case RepinRecords = 'repin_records';

    /**
     * Marks a deferred constraint validation step so it can be journaled and approved separately.
     *
     * The portable Doctrine path emits no statement for it and always reports it satisfied.
     *
     * @since  2.0.0
     */
    case ValidateConstraint = 'validate_constraint';
}
