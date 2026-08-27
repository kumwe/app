<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Infrastructure\Persistence;

use Kumwe\App\BusinessRecord\Application\RecordColumnEncodingPlan;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;

/**
 * Every table-level fact one owned-line write needs, resolved exactly once per command.
 *
 * Writing a line row used to re-resolve the same eight control columns, re-derive the same binding types
 * and re-copy the same owner scope for every row of the collection, so a thousand-line document repeated
 * identical blueprint lookups a thousand times. P4-B names that directly: field and relationship metadata
 * is precompiled once per command. This plan is that metadata — the control columns' physical names, the
 * whole table's binding types, the owner's scope values and the compiled field-encoding plan — and the
 * per-row work that remains is converting the row's own values.
 *
 * @since  2.0.0
 */
final readonly class OwnedLineWritePlan
{
    /**
     * Capture one installed line table's write metadata.
     *
     * @param  PhysicalTableBlueprint      $table            Installed line table the rows are written to.
     * @param  RecordColumnEncodingPlan    $encoding         Compiled field-to-column plan for the line type.
     * @param  string                      $ownerColumn      Physical name of the owner key column.
     * @param  string                      $ownerType        Doctrine binding type of the owner key column.
     * @param  string                      $lineColumn       Physical name of the line key column.
     * @param  string                      $lineType         Doctrine binding type of the line key column.
     * @param  string                      $positionColumn   Physical name of the position column.
     * @param  string                      $versionColumn    Physical name of the version column.
     * @param  string                      $createdByColumn  Physical name of the creation actor column.
     * @param  string                      $createdAtColumn  Physical name of the creation instant column.
     * @param  string                      $updatedByColumn  Physical name of the update actor column.
     * @param  string                      $updatedAtColumn  Physical name of the update instant column.
     * @param  array<string, string|null>  $scopeValues      Owner scope values keyed by physical column name,
     *         identical for every row of the collection and therefore resolved once.
     * @param  array<string, string>       $columnTypes      Doctrine binding type of every column the table
     *         declares, keyed by physical name.
     *
     * @since  2.0.0
     */
    public function __construct(
        public PhysicalTableBlueprint $table,
        public RecordColumnEncodingPlan $encoding,
        public string $ownerColumn,
        public string $ownerType,
        public string $lineColumn,
        public string $lineType,
        public string $positionColumn,
        public string $versionColumn,
        public string $createdByColumn,
        public string $createdAtColumn,
        public string $updatedByColumn,
        public string $updatedAtColumn,
        public array $scopeValues,
        public array $columnTypes,
    ) {
    }
}
