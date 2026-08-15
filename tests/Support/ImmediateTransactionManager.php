<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Support;

use Kumwe\CMS\Application\Persistence\TransactionManager;

/**
 * Executes focused unit-test transactions synchronously without persistence.
 *
 * @since  2.0.0
 */
final class ImmediateTransactionManager implements TransactionManager
{
    /**
     * Execute one callback immediately.
     *
     * @template T
     *
     * @param   callable(): T  $operation  Unit-test work.
     *
     * @return  T  Callback result.
     *
     * @since   2.0.0
     */
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }

    /**
     * Execute an after-commit callback immediately.
     *
     * @param   callable(): void  $operation  Unit-test callback.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function afterCommit(callable $operation): void
    {
        $operation();
    }

    /**
     * Ignore rollback callbacks because this double never rolls back.
     *
     * @param   callable(): void  $operation  Unused compensation callback.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function afterRollback(callable $operation): void
    {
    }
}
