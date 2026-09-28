<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Retention;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Port through which a scheduled job removes a store's expired rows inside a declared budget.
 *
 * The drain owns the loop: it starts at the budget's initial batch, issues one short transaction per
 * batch, resizes the next batch from the last one's duration, and stops when a batch comes back short,
 * when the time budget is spent, or when the batch cap is reached. What counts as expired for each
 * store, and which statement removes it, is the adapter's knowledge; what the caller learns is the
 * result, including which of those three ended the run.
 *
 * @since  2.0.0
 */
interface RetentionDrain
{
    /**
     * Drain one store within a budget.
     *
     * @param   RetentionStore    $store    Store to drain.
     * @param   RetentionBudget   $budget   Time, batch and lock bounds for this run.
     * @param   ExecutionContext  $context  Actor the run is authorized and audited under, for stores whose
     *          removal is itself an audited act.
     *
     * @return  RetentionDrainResult  Rows removed, batches issued and why the run stopped; a store that
     *          is retained for good reports zero rows and a cleared backlog.
     *
     * @since   2.0.0
     */
    public function drain(
        RetentionStore $store,
        RetentionBudget $budget,
        ExecutionContext $context,
    ): RetentionDrainResult;
}
