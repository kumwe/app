<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\SchemaOperation;

/**
 * Port that revalidates stored records against a newer definition version and re-pins them to it.
 *
 * Every business record carries the definition version its values were validated under, so an upgrade
 * that only reshaped tables would leave existing rows claiming a contract nobody checked them against.
 * A `RepinRecords` operation closes that gap, and `BusinessSchemaExecutor` drives this port in bounded
 * batches while holding the schema lock. Refusing a row is the point rather than an inconvenience: a
 * record the target definition rejects must stop the plan, which is what allows the planner to admit a
 * narrowing change at all — without a re-pin step it blocks any plan that drops, renames or retypes a
 * column while older pinned rows survive. Implementations sit beside the record store, since only that side
 * knows how a definition's values are encoded into columns.
 *
 * @since  2.0.0
 */
interface BusinessSchemaRecordRepinGateway
{
    /**
     * Revalidate and re-pin one bounded batch of rows still pinned to an older definition version.
     *
     * Called repeatedly until the result reports completion, each call resuming from the cursor the
     * previous one returned. Rows must be rewritten under their own optimistic version, so a record
     * changed by an ordinary writer during the run fails the batch instead of being overwritten.
     *
     * @param   EntityTypeDefinition                 $definition  Published version rows are re-pinned onto.
     * @param   SchemaOperation                      $operation   Approved repin step naming table and version.
     * @param   PhysicalSchemaBlueprint              $target      Blueprint that version compiled to.
     * @param   array<string, bool|int|string>|null  $cursor      Where the previous batch stopped, or null to start.
     * @param   int                                  $limit       Upper bound on rows this batch may read.
     *
     * @return  SchemaChunkResult  Rows re-pinned, plus the position a further batch resumes from.
     *
     * @throws  BusinessSchemaConflict  When a pinned row cannot satisfy the target definition, or another
     *          writer changed it while the batch was rewriting it.
     *
     * @since   2.0.0
     */
    public function repinChunk(
        EntityTypeDefinition $definition,
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        ?array $cursor,
        int $limit,
    ): SchemaChunkResult;
}
