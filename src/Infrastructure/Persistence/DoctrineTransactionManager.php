<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

/**
 * DBAL implementation of `TransactionManager`, layering completion hooks over the connection's nesting.
 *
 * `Connection::transactional()` already makes nesting invisible, so what this class adds is the hook
 * bookkeeping the contract promises. Every call pushes a frame that collects the callbacks registered
 * while it is open; on success the frame is folded into its parent, so a commit hook registered deep in
 * a nest waits for the outermost scope and runs only once DBAL has committed, while on failure the
 * frame is discarded and its rollback hooks run immediately. Holding that stack is why this is the one
 * class in `Infrastructure\Persistence` that is not `readonly`.
 *
 * @since  2.0.0
 */
final class DoctrineTransactionManager implements TransactionManager
{
    /**
     * Completion callbacks awaiting their scope, one frame per `transactional()` call still open.
     *
     * The last entry is the innermost scope, which is where `afterCommit()` and `afterRollback()` file
     * what they are handed. An empty stack means no transaction is open, which is what makes
     * `afterCommit()` run inline and `afterRollback()` discard its operation.
     *
     * @var    list<array{commit: list<callable(): void>, rollback: list<callable(): void>}>
     * @since  2.0.0
     */
    private array $frames = [];

    /**
     * Bind the manager to the connection whose transactions it drives.
     *
     * @param  Connection  $connection  DBAL connection this manager begins, commits and rolls back on.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Run an operation inside a transaction, committing when it returns and rolling back when it throws.
     *
     * A nested call joins the transaction the connection already has open and pushes its own frame, so
     * the hooks registered inside it are handed to the enclosing frame on success instead of firing
     * there and then. Whatever the operation throws is rethrown unchanged, once the discarded frame's
     * rollback hooks have run.
     *
     * @template T
     *
     * @param   callable(): T  $operation  Work to perform inside the transaction scope.
     *
     * @return  T  Whatever the operation returned, passed straight back.
     *
     * @throws  \LogicException  When the frame this call pushed is no longer on the stack, meaning
     *          something else popped it while the operation was running.
     * @throws  \Doctrine\DBAL\Exception  When the driver cannot begin, commit or roll back.
     *
     * @since   2.0.0
     */
    public function transactional(callable $operation): mixed
    {
        $this->frames[] = ['commit' => [], 'rollback' => []];
        try {
            $result = $this->connection->transactional(
                static fn (Connection $connection): mixed => $operation(),
            );
        } catch (\Throwable $exception) {
            $frame = array_pop($this->frames);
            if (is_array($frame)) {
                $this->invoke($frame['rollback']);
            }
            throw $exception;
        }

        $frame = array_pop($this->frames);
        if (!is_array($frame)) {
            throw new \LogicException('The transaction completion frame was lost.');
        }
        $parent = array_key_last($this->frames);
        if ($parent !== null) {
            array_push($this->frames[$parent]['commit'], ...$frame['commit']);
            array_push($this->frames[$parent]['rollback'], ...$frame['rollback']);
        } else {
            $this->invoke($frame['commit']);
        }

        return $result;
    }

    /**
     * Queue a side effect to run once the outermost transaction has committed.
     *
     * With no transaction open the operation runs inline, so a caller outside a transaction still gets
     * the effect rather than having it silently dropped. Inside a nest the hook rides up frame by frame
     * and fires only after the outermost scope commits, which is what keeps an unundoable effect from
     * running for work a later failure discards.
     *
     * @param   callable(): void  $operation  Side effect to perform once the work is durable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function afterCommit(callable $operation): void
    {
        $frame = array_key_last($this->frames);
        if ($frame === null) {
            $operation();
            return;
        }
        $this->frames[$frame]['commit'][] = $operation;
    }

    /**
     * Queue a compensating action to run if the scope that registered it is discarded.
     *
     * Registering with no transaction open drops the operation, since nothing was staged that could
     * still need undoing. Within a nest a scope that succeeds hands its rollback hooks to the enclosing
     * frame, so they still fire if an outer scope fails afterwards.
     *
     * @param   callable(): void  $operation  Compensating action to perform when the scope is discarded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function afterRollback(callable $operation): void
    {
        $frame = array_key_last($this->frames);
        if ($frame !== null) {
            $this->frames[$frame]['rollback'][] = $operation;
        }
    }

    /**
     * Run one frame's queued callbacks in the order they were registered.
     *
     * A callback that throws abandons the rest of the frame, so a hook that can fail should contain its
     * own failure rather than take the hooks queued behind it down with it.
     *
     * @param   list<callable(): void>  $operations  Callbacks collected by a single completion frame.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function invoke(array $operations): void
    {
        foreach ($operations as $operation) {
            $operation();
        }
    }
}
