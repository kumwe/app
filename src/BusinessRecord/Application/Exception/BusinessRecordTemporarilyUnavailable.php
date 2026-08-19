<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Exception;

use Throwable;

/**
 * Signals a record operation that failed for a reason a fresh attempt may not hit again.
 *
 * The Doctrine adapters raise it for every driver failure they cannot attribute to the command,
 * deadlocks and lock-wait timeouts among them, which keeps `Doctrine\DBAL\Exception` inside the
 * adapter while the original stays reachable as the chained previous exception. The mutation fence
 * adds two cases of its own — a lock requested outside an active transaction, and a platform whose
 * share-lock syntax it does not know — and `BusinessRecordMutationGeneration` raises it when the
 * installation resolved inside an operation is no longer the one the lock was taken against. It is
 * the one record exception `BusinessRecordService` retries: an idempotent command makes three
 * attempts before letting it out, and three lost idempotency races end here as well.
 *
 * @since  2.0.0
 */
final class BusinessRecordTemporarilyUnavailable extends BusinessRecordException
{
    /**
     * Build the signal, chaining the failure it stands in for when there is one.
     *
     * @param  ?Throwable  $previous  Driver or infrastructure failure being translated, kept for the
     *         log; null when the condition was detected directly rather than caught.
     *
     * @since  2.0.0
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            'business_record.temporarily_unavailable',
            'The business-record operation is temporarily unavailable.',
            $previous,
        );
    }
}
