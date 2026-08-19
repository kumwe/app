<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Infrastructure\Trust;

use LogicException;

/**
 * Process-local nesting state for database platforms whose durable advisory-lock fallback is not reentrant.
 *
 * MySQL's `GET_LOCK` and PostgreSQL's session advisory locks may be taken twice by the same connection,
 * but the row `DoctrineTrustStoreRepository` falls back to on every other platform cannot be: its unique
 * key would refuse the process that already owns it, turning a lifecycle operation that calls another
 * one into a self-inflicted refusal. Counting depth here lets the nested call reuse the claim already
 * held, which is what makes lifecycle serialization behave the same on every supported platform. The
 * counter says nothing about other processes — the durable row stays the cross-process authority.
 *
 * @since  2.0.0
 */
final class ReentrantLifecycleLock
{
    /**
     * How many lifecycle operations this process currently has open inside the lock.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $depth = 0;

    /**
     * Report whether this process is already inside the lifecycle lock.
     *
     * The repository reads it to choose between claiming the durable lock and merely nesting inside the
     * claim it already owns.
     *
     * @return  bool  True while at least one operation has entered and not yet left.
     *
     * @since   2.0.0
     */
    public function held(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Record that another operation has entered the lock.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function enter(): void
    {
        ++$this->depth;
    }

    /**
     * Record that an operation has left the lock.
     *
     * @return  void
     *
     * @throws  LogicException  When more operations leave than entered, which means a caller is
     *          releasing a lock it never took and the nesting state can no longer be trusted.
     *
     * @since   2.0.0
     */
    public function leave(): void
    {
        if ($this->depth === 0) {
            throw new LogicException('The extension lifecycle lock nesting state is unbalanced.');
        }
        --$this->depth;
    }
}
