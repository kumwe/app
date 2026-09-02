<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Persistence;

/**
 * Read-only view of whether the connection a use case would run on already holds an open transaction.
 *
 * `TransactionManager` deliberately makes nesting invisible, which is right for the services that compose:
 * an inner call joins the outer scope. A few boundaries cannot be composed that way — one that recovers
 * from a lost unique-index race has to observe the losing statement's rollback, which never happens while
 * an enclosing transaction is still open — and those have to know, before they begin, that no scope is
 * active. This port answers exactly that question without handing the application layer a connection;
 * Infrastructure adapts it to the driver (`DoctrineTransactionState` today).
 *
 * @since  2.0.0
 */
interface TransactionState
{
    /**
     * Report whether a transaction is currently open on the underlying connection.
     *
     * @return  bool  True while any transaction scope, however deeply nested, remains open.
     *
     * @since   2.0.0
     */
    public function isActive(): bool;
}
