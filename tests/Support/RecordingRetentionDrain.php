<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\App\Application\Retention\RetentionBudget;
use Kumwe\App\Application\Retention\RetentionDrain;
use Kumwe\App\Application\Retention\RetentionDrainResult;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Retention drain double that records every request so handler tests can assert store and budget.
 *
 * @since  2.0.0
 */
final class RecordingRetentionDrain implements RetentionDrain
{
    /**
     * Every drain request received, in order.
     *
     * @var    list<array{store: RetentionStore, budget: RetentionBudget}>
     * @since  2.0.0
     */
    public array $requests = [];

    /**
     * Record the request and report an immediately cleared backlog.
     *
     * @param   RetentionStore    $store    Store requested.
     * @param   RetentionBudget   $budget   Budget requested.
     * @param   ExecutionContext  $context  Actor; unused.
     *
     * @return  RetentionDrainResult  Zero rows, cleared.
     *
     * @since   2.0.0
     */
    public function drain(
        RetentionStore $store,
        RetentionBudget $budget,
        ExecutionContext $context,
    ): RetentionDrainResult {
        $this->requests[] = ['store' => $store, 'budget' => $budget];

        return new RetentionDrainResult($store, 0, 1, 0.0, $budget->initialBatch(), true, false);
    }
}
