<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Exception;

/**
 * Signals that a concurrent command claimed the same idempotency scope digest first.
 *
 * `BusinessRecordIdempotencyRepository::begin()` raises this when its insert loses the unique
 * constraint on the scope digest, which is what makes the store, rather than an earlier read, the
 * arbiter of the race. It is a retry signal and not a caller error: `BusinessRecordService` catches
 * it, lets the transaction roll back, and starts the attempt over, so the retry finds the winner's
 * entry and takes the replay path instead of mutating twice. Three consecutive losses leave the retry
 * loop as `BusinessRecordTemporarilyUnavailable`, so this never escapes `BusinessRecordService`.
 *
 * @since  2.0.0
 */
final class BusinessRecordIdempotencyRace extends BusinessRecordException
{
    /**
     * Fix the stable code and operator message every instance of this race carries.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('business_record.idempotency_race', 'A concurrent idempotent command won this key.');
    }
}
