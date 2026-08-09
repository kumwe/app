<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

/**
 * Raised when a mutation finds the record at a version other than the one the caller expected.
 *
 * Every command that changes an existing record carries the version the caller believes it read; only a
 * create, having no prior version, is exempt. `BusinessRecordService` compares that version against the
 * record it loaded, and `DoctrineBusinessRecordWriteRepository` re-checks it in the statement itself, so
 * a write that lost a race touches no row and becomes this failure once the current version has been
 * read back. Both versions travel on the exception because retrying with the same expected version
 * conflicts again — the caller has to refetch and decide what to keep.
 *
 * A record that has disappeared rather than moved on is reported as `BusinessRecordNotFound` instead.
 *
 * @since  2.0.0
 */
final class BusinessRecordVersionConflict extends BusinessRecordException
{
    /**
     * Report a lost optimistic-concurrency race under the `business_record.version_conflict` code.
     *
     * @param  int   $expectedVersion  Version the caller submitted the mutation against.
     * @param  ?int  $actualVersion    Version the stored record carries now, or null when the thrower
     *         could not read the current version back.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly int $expectedVersion, public readonly ?int $actualVersion)
    {
        parent::__construct(
            'business_record.version_conflict',
            'The business record changed after the supplied expected version.',
        );
    }
}
