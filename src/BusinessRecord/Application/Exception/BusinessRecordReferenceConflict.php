<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

/**
 * Signals that a business-record reference is missing, or that it stands in the way of this mutation.
 *
 * Two sources raise it. `DoctrineBusinessRecordWriteRepository` translates a foreign-key violation
 * into it so that no DBAL exception escapes the adapter, naming the relationship it was writing when
 * it has one. `BusinessRecordService` raises it without a handle when the record graph contradicts
 * itself: a record whose pinned definition resolves to a different scope than the one it was read
 * under, an identity whose record key does not match the row it addressed, a relationship target
 * that belongs to another definition or another scope, or a definition carrying no identity field at
 * all. In each case the reference the command was built on cannot be trusted, so the mutation is
 * refused rather than applied against a guess.
 *
 * @since  2.0.0
 */
final class BusinessRecordReferenceConflict extends BusinessRecordException
{
    /**
     * Build the conflict, naming the relationship it was raised against when one is known.
     *
     * @param  ?string  $relationship  Handle of the relationship whose write hit the constraint, or
     *         null when the conflict was found outside a relationship write.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly ?string $relationship = null)
    {
        parent::__construct(
            'business_record.reference_conflict',
            'A business-record reference is missing or prevents this mutation.',
        );
    }
}
