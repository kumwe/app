<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use Kumwe\CMS\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\SchemaOperation;

/**
 * Port through which schema plans reach the live database, one approved operation at a time.
 *
 * The planner and the executor reason in blueprints and semantic operations and never in SQL; this port
 * is where that vocabulary becomes driver work, which is what keeps a plan approved on one engine
 * meaningful on another. Two obligations shape every implementation. Verification comes before and
 * after every change — `operationSatisfied()` lets the executor skip a step a previous attempt already
 * applied, and the same check is re-run afterwards so a statement that silently did nothing cannot be
 * journaled as done. And live state is never assumed: a table that exists but does not match the
 * blueprint is reported as drift rather than adapted to, because the alternative is a checksum that
 * claims a schema nobody has. Row-rewriting operations are deliberately excluded from `execute()` and
 * only reachable in bounded batches, so no single call can hold a table for the length of a table scan.
 *
 * @since  2.0.0
 */
interface PhysicalSchemaGateway
{
    /**
     * Returns the expected logical blueprint projected onto objects that physically exist.
     *
     * The answer is deliberately three-valued: the blueprint back when every table is present and
     * matches, null when none of them exist yet, and a raised conflict when the installation is
     * partial or an existing table disagrees with what was compiled.
     *
     * @param   PhysicalSchemaBlueprint  $expected  Blueprint the caller believes is installed.
     *
     * @return  ?PhysicalSchemaBlueprint  The same blueprint when fully installed, null when absent entirely.
     *
     * @throws  BusinessSchemaConflict  When a present table has drifted from the blueprint, or only some
     *          of the blueprint's tables exist.
     *
     * @since   2.0.0
     */
    public function inspect(PhysicalSchemaBlueprint $expected): ?PhysicalSchemaBlueprint;

    /**
     * Report whether an operation's postcondition already holds in the live database.
     *
     * Asked before a step runs, so a recovered execution skips work an earlier attempt completed, and
     * again afterwards as the proof that the step actually took effect.
     *
     * @param   SchemaOperation          $operation  Step whose intended end state is being checked.
     * @param   PhysicalSchemaBlueprint  $target     Blueprint the plan is moving the schema towards.
     *
     * @return  bool  True when the database already looks the way the step intends to leave it.
     *
     * @since   2.0.0
     */
    public function operationSatisfied(
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
    ): bool;

    /**
     * Apply one approved shape-changing operation to the live database.
     *
     * A step whose postcondition already holds is a no-op, which makes re-execution after an
     * interruption safe. Row-rewriting kinds are refused here: they belong to the chunked methods.
     *
     * @param   SchemaOperation          $operation  Approved step to realise as driver statements.
     * @param   PhysicalSchemaBlueprint  $target     Blueprint supplying the shape names resolve against.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function execute(SchemaOperation $operation, PhysicalSchemaBlueprint $target): void;

    /**
     * Drop a plan-created table only after proving its exact shape and emptiness.
     *
     * Used when an initial, purely additive plan fails part way: the tables it created are removed so a
     * retry starts clean. Anything that would make the removal a data loss stops the compensation
     * instead, leaving the plan recovery-required for an operator.
     *
     * @param   SchemaOperation  $operation  Completed create-table step being undone.
     *
     * @return  bool  True when the table was found and dropped, false when it was already absent.
     *
     * @throws  BusinessSchemaConflict  When the table no longer matches what the step created, or holds
     *          at least one row.
     *
     * @since   2.0.0
     */
    public function compensateCreateTable(SchemaOperation $operation): bool;

    /**
     * Report whether any stored record is still pinned to a definition version below the given one.
     *
     * This is the guard that keeps a narrowing upgrade from reaching rows nobody revalidated: the
     * planner and the executor both consult it before allowing a drop, retype, or transform to run
     * without an accompanying re-pin step.
     *
     * @param   PhysicalSchemaBlueprint  $installed          Blueprint of the schema as currently installed.
     * @param   int                      $definitionVersion  Version rows must have reached to be considered current.
     *
     * @return  bool  True when at least one record row predates that version.
     *
     * @throws  BusinessSchemaConflict  When the installed record table is missing from the database.
     *
     * @since   2.0.0
     */
    public function hasRowsPinnedBefore(PhysicalSchemaBlueprint $installed, int $definitionVersion): bool;

    /**
     * Fill one bounded batch of rows whose new column is still unset.
     *
     * Only rows that are still null are visited, so re-running a batch after an interruption neither
     * skips rows nor overwrites values an earlier pass computed.
     *
     * @param   SchemaOperation                      $operation  Approved backfill step and its source value.
     * @param   PhysicalSchemaBlueprint              $target     Blueprint the column belongs to.
     * @param   array<string, bool|int|string>|null  $cursor     Where the previous batch stopped, or null to start.
     * @param   int                                  $limit      Upper bound on rows this batch may read.
     *
     * @return  SchemaChunkResult  Rows filled, plus the position a further batch resumes from.
     *
     * @throws  BusinessSchemaConflict  When a visited row carries an identity the gateway cannot bind as
     *          a parameter.
     *
     * @since   2.0.0
     */
    public function backfillChunk(
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        ?array $cursor,
        int $limit,
    ): SchemaChunkResult;

    /**
     * Recompute one bounded batch of rows into the shadow column a type change writes through.
     *
     * Unlike a backfill this visits every row in identity order and always rewrites, because the value
     * is derived from the row rather than merely absent; the executor therefore never treats a
     * transform as already satisfied and re-runs the batch from its last checkpoint after a failure.
     *
     * @param   SchemaOperation                      $operation  Approved transform step and its expression.
     * @param   PhysicalSchemaBlueprint              $target     Blueprint both columns belong to.
     * @param   array<string, bool|int|string>|null  $cursor     Where the previous batch stopped, or null to start.
     * @param   int                                  $limit      Upper bound on rows this batch may read.
     *
     * @return  SchemaChunkResult  Rows recomputed, plus the position a further batch resumes from.
     *
     * @throws  BusinessSchemaConflict  When a visited row carries an identity the gateway cannot bind as
     *          a parameter.
     *
     * @since   2.0.0
     */
    public function transformChunk(
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        ?array $cursor,
        int $limit,
    ): SchemaChunkResult;
}
