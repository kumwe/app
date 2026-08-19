<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

/**
 * Contract for reclaiming idempotency records whose retention window has closed.
 *
 * An idempotency store grows with every replay-protected request, so something has to reclaim the
 * records nobody can replay any more. An implementation deletes in bounded batches and must leave
 * any record a request still owns — an unexpired lock, or an owner token not yet released — in
 * place, so a purge running beside live traffic never strips the protection from an operation still
 * in flight. The scheduled `PurgeIdempotencyRecordsHandler` drives the loop, and stops as soon as a
 * call returns a short batch.
 *
 * @since  2.0.0
 */
interface IdempotencyPurger
{
    /**
     * Delete one bounded batch of expired, unowned idempotency records.
     *
     * The count is the caller's continuation signal: a full batch means more expired records may
     * remain, while anything short of the batch size means the backlog is drained for now.
     *
     * @param   int  $batchSize  Upper bound on how many records this call may remove.
     *
     * @return  int  Number of records actually deleted, which is zero when nothing has expired.
     *
     * @since   2.0.0
     */
    public function purgeExpired(int $batchSize = 1_000): int;
}
