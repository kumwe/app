<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Closure;
use RuntimeException;

/**
 * Runs a shutdown callback only in the process that registered it.
 *
 * PHP copies registered shutdown functions into a child created by `pcntl_fork()`. Without an owning-process
 * check, both parent and child can execute one process-scoped cleanup concurrently against the same database.
 * This guard captures the registering PID and makes the inherited child callback a no-op.
 *
 * @since  2.0.0
 */
final readonly class ProcessOwnedShutdown
{
    /**
     * Callback that is safe to run once in the owning process.
     *
     * @var    Closure(): void
     * @since  2.0.0
     */
    private Closure $callback;

    /**
     * Capture an explicit owner and callback.
     *
     * The explicit constructor keeps the ownership decision dependency-free and directly testable. Runtime
     * registration should normally use {@see self::capture()} so the actual process ID is retained.
     *
     * @param  int               $ownerProcessId  Positive PID of the process that owns the cleanup.
     * @param  callable(): void  $callback        Cleanup to invoke only for that process.
     *
     * @throws  RuntimeException  When the owner PID is not positive.
     *
     * @since  2.0.0
     */
    public function __construct(private int $ownerProcessId, callable $callback)
    {
        if ($ownerProcessId < 1) {
            throw new RuntimeException('A process-owned shutdown requires a positive owner PID.');
        }
        $this->callback = Closure::fromCallable($callback);
    }

    /**
     * Capture the process that is registering a shutdown callback.
     *
     * @param   callable(): void  $callback  Cleanup to invoke only in the current process.
     *
     * @return  self  Callback guarded by the current process identity.
     *
     * @throws  RuntimeException  When PHP cannot identify the current process.
     *
     * @since   2.0.0
     */
    public static function capture(callable $callback): self
    {
        $processId = getmypid();
        if (!is_int($processId) || $processId < 1) {
            throw new RuntimeException('The integration process identity is unavailable.');
        }

        return new self($processId, $callback);
    }

    /**
     * Invoke the callback during normal PHP shutdown when this remains the owning process.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function __invoke(): void
    {
        $processId = getmypid();
        if (!is_int($processId)) {
            return;
        }
        $this->runFor($processId);
    }

    /**
     * Apply the ownership decision for one current PID.
     *
     * This narrow seam lets the fork rule be proved without creating a child process in the unit lane.
     *
     * @param  int  $currentProcessId  PID of the process attempting to run the callback.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function runFor(int $currentProcessId): void
    {
        if ($currentProcessId !== $this->ownerProcessId) {
            return;
        }

        ($this->callback)();
    }
}
