<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

/**
 * Raised when a browse specification cannot be compiled into SQL against the installed schema.
 *
 * `DoctrineBusinessRecordQueryCompiler` funnels every refusal into this one type: a field the definition
 * does not mark filterable, sortable, searchable, readable or reportable; a comparison, sort or
 * aggregate aimed at a composite field where it would be ambiguous; a scope the installed schema lacks
 * or requires; a cursor minted for a different specification or carrying the wrong number of sort
 * values; an unknown field, relationship or reference target; and a plan that exceeds the 64-operation
 * budget. The compiler also converts the `InvalidArgumentException` its collaborators raise into this
 * type, so a caller running a browse has a single class of specification error to handle.
 *
 * The failure is in the query, not the store: resubmitting the same specification fails identically.
 *
 * @since  2.0.0
 */
final class InvalidBusinessRecordQuery extends BusinessRecordException
{
    /**
     * Report an uncompilable specification under the `business_record.invalid_query` code.
     *
     * @param  string  $reason  Operator-facing sentence naming the part of the specification the
     *         compiler refused; required, since every call site knows which rule it hit.
     *
     * @since  2.0.0
     */
    public function __construct(string $reason)
    {
        parent::__construct('business_record.invalid_query', $reason);
    }
}
