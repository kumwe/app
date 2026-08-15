<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Persistence;

/**
 * Contract for running work atomically and for deferring side effects until the transaction settles.
 *
 * Application owns this contract because a transaction boundary is a use-case decision, not a driver
 * detail: the layer that knows which writes have to settle together is the layer that gets to say so.
 * Infrastructure supplies the adapter that keeps the promise — `DoctrineTransactionManager` today — and
 * nothing here names a connection, a platform or a query builder, so a second adapter owes behaviour
 * rather than a shape.
 *
 * Application services take this rather than a connection, which is what lets a use case compose:
 * calling one service from inside another's transaction joins the scope already open instead of
 * starting a second one, so the same service works standalone and as a step in a larger operation. The
 * two hooks exist because the effects a use case triggers outside the database — cache eviction, queue
 * writes, filesystem publication — cannot be rolled back, and so must not fire until the database work
 * they describe is durable. An implementation owes two guarantees: nesting is invisible to the caller,
 * and a commit hook registered anywhere in a nest waits for the outermost commit, while a rollback hook
 * fires as soon as the scope that registered it is discarded.
 *
 * @since  2.0.0
 */
interface TransactionManager
{
    /**
     * Run an operation inside a transaction, committing when it returns and rolling back when it throws.
     *
     * A nested call joins the enclosing scope, so only the outermost call commits and a failure anywhere
     * in the nest discards the whole of it. Anything the operation throws reaches the caller unchanged.
     *
     * @template T
     *
     * @param   callable(): T  $operation  Work to perform inside the transaction scope.
     *
     * @return  T  Whatever the operation returned, passed straight back.
     *
     * @since   2.0.0
     */
    public function transactional(callable $operation): mixed;

    /**
     * Runs after the outermost transaction commits, or immediately when no transaction is active.
     *
     * This is where an effect that cannot be undone belongs, so it never fires for work that a later
     * failure in the same nest discards.
     *
     * @param   callable(): void  $operation  Side effect to perform once the work is durable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function afterCommit(callable $operation): void;

    /**
     * Runs if the transaction scope that registered it rolls back.
     *
     * Registering while no transaction is active is a no-op, since there is nothing left to compensate.
     *
     * @param   callable(): void  $operation  Compensating action to perform when the scope is discarded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function afterRollback(callable $operation): void;
}
