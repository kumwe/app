<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;

/**
 * One field's share of a compiled column-encoding plan.
 *
 * The decisions `RecordValueCodec::encodeColumns()` used to remake for every row — whether the field is
 * written at all, and which physical column holds each of its logical storage names — depend only on the
 * definition and the installed table, never on the row. This is those decisions made once, so encoding a
 * row is reduced to converting its values.
 *
 * @since  2.0.0
 */
final readonly class PlannedFieldEncoding
{
    /**
     * Capture one writable field and its resolved storage columns.
     *
     * @param  FieldDefinition        $field    Field whose values this entry encodes.
     * @param  array<string, string>  $columns  Physical column name for every logical storage name the
     *         field can yield; a logical name the installed table has no column for is simply absent,
     *         which is the same silent skip the per-row path applied.
     *
     * @since  2.0.0
     */
    public function __construct(
        public FieldDefinition $field,
        public array $columns,
    ) {
    }
}
