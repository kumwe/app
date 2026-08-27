<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

/**
 * Every per-field decision one (definition, table) pair needs to encode rows, made exactly once.
 *
 * Encoding a row used to walk the whole definition and filter the whole column list for every field of
 * every row, so a thousand-line document repeated the same skip checks and column resolutions a thousand
 * times. P4-B names that directly: field and relationship metadata is precompiled once per command. The
 * plan is pure data — `RecordValueCodec` both builds it and consumes it, so the single-row path and the
 * bulk path cannot drift apart.
 *
 * @since  2.0.0
 */
final readonly class RecordColumnEncodingPlan
{
    /**
     * Capture the writable fields of one definition against one installed table.
     *
     * @param  list<PlannedFieldEncoding>  $fields  Writable fields in declared order, each with its
     *         resolved storage columns. A field the encode path always skips — the UUID identity whose
     *         value lives in the record key, a virtual computation, a field the table has no column
     *         for — is compiled out rather than re-tested per row.
     *
     * @since  2.0.0
     */
    public function __construct(
        public array $fields,
    ) {
    }
}
