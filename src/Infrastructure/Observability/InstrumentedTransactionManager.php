<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Kumwe\Transaction\Contract\TransactionManager;
use Throwable;

/**
 * Counts and times outermost transactions, and classifies the ones that roll back on lock conflicts.
 *
 * It decorates the host transaction manager rather than replacing it: nesting, hooks and rollback
 * semantics are the inner manager's, and this class only observes the outermost call. The failure
 * classes are the two a scale regression shows first — deadlock victims and lock-wait timeouts — found
 * anywhere in the exception chain, so a domain exception that wraps a driver deadlock still counts. The
 * recorder never throws, so observing a transaction cannot change its outcome.
 *
 * @since  2.0.0
 */
final class InstrumentedTransactionManager implements TransactionManager
{
    /**
     * Current nesting depth of `transactional()` calls on this manager.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $depth = 0;

    /**
     * Wrap the host manager.
     *
     * @param  TransactionManager  $inner    Manager that owns the physical transactions.
     * @param  MetricRecorder      $metrics  Recorder the counters and histogram are written to.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly TransactionManager $inner, private readonly MetricRecorder $metrics)
    {
    }

    /**
     * Run the operation through the inner manager, observing it when it is the outermost call.
     *
     * @template T
     *
     * @param   callable(): T  $operation  Work to perform inside the transaction scope.
     *
     * @return  T  Whatever the operation returned.
     *
     * @since   2.0.0
     */
    public function transactional(callable $operation): mixed
    {
        $outermost = $this->depth === 0;
        $this->depth++;
        $started = hrtime(true);
        try {
            $result = $this->inner->transactional($operation);
        } catch (Throwable $failure) {
            if ($outermost) {
                $this->observe($started, 'rolled_back');
                $class = $this->classify($failure);
                if ($class !== null) {
                    $this->metrics->increment(MetricCatalog::TRANSACTION_FAILURES, ['class' => $class]);
                }
            }
            throw $failure;
        } finally {
            $this->depth--;
        }
        if ($outermost) {
            $this->observe($started, 'committed');
        }

        return $result;
    }

    /**
     * Delegate a post-commit hook to the inner manager.
     *
     * @param   callable(): void  $operation  Side effect to run once the outermost scope commits.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function afterCommit(callable $operation): void
    {
        $this->inner->afterCommit($operation);
    }

    /**
     * Delegate a rollback hook to the inner manager.
     *
     * @param   callable(): void  $operation  Compensation to run if the scope is discarded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function afterRollback(callable $operation): void
    {
        $this->inner->afterRollback($operation);
    }

    /**
     * Record the outcome counter and duration of one outermost transaction.
     *
     * @param   int     $started  Monotonic start in nanoseconds.
     * @param   string  $outcome  `committed` or `rolled_back`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function observe(int $started, string $outcome): void
    {
        $this->metrics->increment(MetricCatalog::TRANSACTIONS, ['outcome' => $outcome]);
        $this->metrics->observe(MetricCatalog::TRANSACTION_DURATION, [], (hrtime(true) - $started) / 1_000_000_000);
    }

    /**
     * Find a lock-conflict class anywhere in the failure chain.
     *
     * @param   Throwable  $failure  Failure the transaction rolled back on.
     *
     * @return  ?string  `deadlock`, `lock_timeout`, or null for any other failure.
     *
     * @since   2.0.0
     */
    private function classify(Throwable $failure): ?string
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof DeadlockException) {
                return 'deadlock';
            }
            if ($cause instanceof LockWaitTimeoutException) {
                return 'lock_timeout';
            }
        }

        return null;
    }
}
