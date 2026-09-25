<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Retention;

/**
 * The outcome of one exact, operator-requested row count of a hot store.
 *
 * @since  2.0.0
 */
final readonly class LedgerCount
{
    /**
     * Record the outcome.
     *
     * @param  RetentionStore  $store                Store counted.
     * @param  ?int            $rows                 Exact row count, or null when the statement timed out.
     * @param  bool            $timedOut             True when the statement hit its timeout and was cancelled.
     * @param  float           $elapsedMilliseconds  Wall time the statement took or ran before cancellation.
     * @param  int             $timeoutMilliseconds  Timeout the statement ran under.
     * @param  string          $costClass            Declared cost class; always `table_scan` for an exact count.
     *
     * @since  2.0.0
     */
    public function __construct(
        public RetentionStore $store,
        public ?int $rows,
        public bool $timedOut,
        public float $elapsedMilliseconds,
        public int $timeoutMilliseconds,
        public string $costClass,
    ) {
    }
}
