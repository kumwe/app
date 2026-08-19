<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Exception;

/**
 * Raised when a mutation reaches a record whose definition closes it in its current workflow state.
 *
 * This is not a policy denial: the caller may be fully authorized, and the record is simply closed. A
 * workflow binding that names the record's current state in its immutable states makes every mutation of
 * the record's own fields and its owned lines refuse with this failure, on every surface, while declared
 * workflow transitions — the way an approved document still becomes a delivered one — and the audited
 * delete lifecycle stay open. The correction path is a new record of the same definition carrying a
 * `RelationshipKind::Reversal` link back to this one, written through the ordinary aggregate command.
 *
 * @since  2.0.0
 */
final class BusinessRecordImmutable extends BusinessRecordException
{
    /**
     * Report a refused mutation of a closed document under the `business_record.immutable` code.
     *
     * @param  string  $workflowState  Declared immutable state the record currently occupies.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly string $workflowState)
    {
        parent::__construct(
            'business_record.immutable',
            'The business record is immutable in its current workflow state and is corrected by a linked reversal.',
        );
    }
}
