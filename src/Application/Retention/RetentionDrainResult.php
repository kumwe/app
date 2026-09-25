<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Retention;

/**
 * What one drain run of a store removed, how long it took, and why it stopped.
 *
 * The three stopping reasons are distinct because they mean different things to an operator: a run
 * that cleared its backlog is healthy; a run that ran out of its time budget while rows remained is
 * keeping up only if the next run clears them; and a run that hit its batch cap is being throttled by
 * configuration rather than by the engine. The drain rate derived here is the instantaneous rate of
 * the run, not a sustained capacity; the policy's duty cycle turns it into one.
 *
 * @since  2.0.0
 */
final readonly class RetentionDrainResult
{
    /**
     * Record one run's outcome.
     *
     * @param  RetentionStore  $store            Store the run drained.
     * @param  int             $rowsDrained      Rows actually removed or compacted.
     * @param  int             $batches          Batch transactions issued.
     * @param  float           $elapsedSeconds   Wall time from the first batch to the last.
     * @param  int             $finalBatch       Batch size the adaptive sizer had converged on.
     * @param  bool            $backlogCleared   True when the last batch came back short, meaning nothing
     *         eligible remained at that instant.
     * @param  bool            $budgetExhausted  True when the run stopped because its time budget or its
     *         batch cap ran out while rows may have remained.
     *
     * @since  2.0.0
     */
    public function __construct(
        public RetentionStore $store,
        public int $rowsDrained,
        public int $batches,
        public float $elapsedSeconds,
        public int $finalBatch,
        public bool $backlogCleared,
        public bool $budgetExhausted,
    ) {
    }

    /**
     * Rows removed per second of run time.
     *
     * @return  ?float  Null when the run removed nothing or took no measurable time.
     *
     * @since   2.0.0
     */
    public function rowsPerSecond(): ?float
    {
        if ($this->rowsDrained < 1 || $this->elapsedSeconds <= 0.0) {
            return null;
        }

        return $this->rowsDrained / $this->elapsedSeconds;
    }
}
