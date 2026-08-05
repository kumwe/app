<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

interface TransactionManager
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;

    /** Runs after the outermost transaction commits, or immediately when no transaction is active. */
    public function afterCommit(callable $operation): void;

    /** Runs if the transaction scope that registered it rolls back. */
    public function afterRollback(callable $operation): void;
}
