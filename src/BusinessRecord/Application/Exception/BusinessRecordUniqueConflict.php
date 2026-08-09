<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

/**
 * Signals that a value a record command tried to store is already held by another row.
 *
 * `DoctrineBusinessRecordWriteRepository` translates a unique-constraint violation into this, which
 * is the deliberate design: uniqueness is decided by the index inside the write transaction rather
 * than by a read-then-write check a concurrent command could slip past, and the DBAL exception never
 * leaves the adapter. Identity columns, fields declared unique, and relationship join rows all carry
 * unique indexes, so all three land here; only a relationship write is able to say which handle it
 * was on, and a conflict raised by a record-table statement arrives with no handle at all.
 *
 * @since  2.0.0
 */
final class BusinessRecordUniqueConflict extends BusinessRecordException
{
    /**
     * Build the conflict, naming the relationship it was raised against when one is known.
     *
     * @param  ?string  $field  Handle of the relationship whose write hit the constraint, or null
     *         when the violation came from a record-table statement that names no single handle.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly ?string $field = null)
    {
        parent::__construct('business_record.unique_conflict', 'A unique business-record value is already in use.');
    }
}
