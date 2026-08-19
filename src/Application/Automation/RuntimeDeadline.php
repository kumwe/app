<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use RuntimeException;

/**
 * Wall-clock bound around one unit of work, enforced by `SIGALRM` rather than by cooperation.
 *
 * Every durable worker in the platform makes the same bet: a process that wedges is recovered by its
 * lease expiring and a sibling taking the work over. That bet only pays out if the wedged process
 * eventually notices, which nothing inside a blocked `fread()` on a hung endpoint ever does. The alarm
 * is the one mechanism that does not need the work's cooperation: it arrives as a signal, is raised as
 * an exception at the next tick, and turns a process pinned forever into an ordinary failure the caller
 * records and moves past.
 *
 * The signal functions are required rather than best-effort, because a runtime without them would
 * silently run unbounded — the failure mode this class exists to remove. The previous `SIGALRM`
 * handler is restored and any pending alarm cleared on the way out, including when the operation threw,
 * so nesting a deadline inside a caller that had its own leaves that caller's arrangement intact.
 *
 * @since  2.0.0
 */
final readonly class RuntimeDeadline
{
    /**
     * Fix the budget and the message the expiry is reported with.
     *
     * @param  int     $seconds  Wall-clock seconds the operation may take; one or more.
     * @param  string  $expiry   Message the raised failure carries, written for the operator who reads
     *         it in a failed-attempt record rather than for the caller that catches it.
     *
     * @since  2.0.0
     */
    public function __construct(private int $seconds, private string $expiry)
    {
    }

    /**
     * Run one operation, raising the expiry failure if it is still running when the budget is spent.
     *
     * @param   callable(): void  $operation  Work to run under the deadline.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the budget is not positive or the pcntl signal functions are
     *          missing, and when the alarm fires before the operation returns.
     *
     * @since   2.0.0
     */
    public function run(callable $operation): void
    {
        if (
            $this->seconds < 1
            || !function_exists('pcntl_alarm')
            || !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
        ) {
            throw new RuntimeException('Durable workers require a positive, enforceable handler runtime limit.');
        }
        pcntl_async_signals(true);
        $previous = pcntl_signal_get_handler(SIGALRM);
        $expiry = $this->expiry;
        pcntl_signal(SIGALRM, static function () use ($expiry): never {
            throw new RuntimeException($expiry);
        });
        pcntl_alarm($this->seconds);
        try {
            $operation();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previous);
        }
    }
}
