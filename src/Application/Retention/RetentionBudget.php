<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Retention;

use InvalidArgumentException;

/**
 * The bounds one drain run works inside, and the rule that sizes each batch from the last one's cost.
 *
 * A fixed batch size is wrong on every engine at once: what is a ten-millisecond delete on a warm
 * index is a multi-second lock on a cold one, and a schedule seeded with one number either starves
 * the drain or holds live traffic behind it. The budget therefore fixes what actually matters — how
 * long a run may go on, how long any one batch may hold its locks, and the smallest and largest batch
 * the store's delete accepts — and derives the batch size as it goes: a batch that finished well
 * inside the lock budget doubles the next one, a batch that overran it halves the next one, and
 * everything else keeps its size. The result converges on the largest batch the engine can settle
 * within the lock budget right now, which is the rate the run can sustain without becoming the
 * contention it was meant to relieve.
 *
 * @since  2.0.0
 */
final readonly class RetentionBudget
{
    /**
     * Declare the bounds.
     *
     * @param   int  $timeBudgetSeconds       Wall-clock seconds the whole run may spend, 1 to 3600.
     * @param   int  $minimumBatch            Smallest batch, at least one.
     * @param   int  $maximumBatch            Largest batch, at least the minimum.
     * @param   int  $lockBudgetMilliseconds  Longest one batch should hold its locks, 1 to 60000.
     * @param   int  $maximumBatches          Most batches one run may issue, at least one; a cap for callers
     *          that still budget by count.
     *
     * @throws  InvalidArgumentException  When a bound is outside its range or the batch bounds are inverted.
     *
     * @since   2.0.0
     */
    public function __construct(
        public int $timeBudgetSeconds,
        public int $minimumBatch,
        public int $maximumBatch,
        public int $lockBudgetMilliseconds,
        public int $maximumBatches = 100_000,
    ) {
        if (
            $timeBudgetSeconds < 1 || $timeBudgetSeconds > 3_600 || $minimumBatch < 1
            || $maximumBatch < $minimumBatch || $lockBudgetMilliseconds < 1 || $lockBudgetMilliseconds > 60_000
            || $maximumBatches < 1
        ) {
            throw new InvalidArgumentException('A retention budget declares an invalid bound.');
        }
    }

    /**
     * Narrow this budget with the optional overrides a job payload may carry.
     *
     * Only narrowing is possible: a payload may shorten the time budget, lower the batch ceiling or cap
     * the batch count, never widen what the policy declared, so a mistyped schedule cannot turn a
     * bounded drain into a table-wide write.
     *
     * @param   ?int  $timeBudgetSeconds  Shorter wall-clock budget, or null to keep the declared one.
     * @param   ?int  $maximumBatch       Lower batch ceiling, or null to keep the declared one.
     * @param   ?int  $maximumBatches     Cap on batches per run, or null to keep the declared one.
     *
     * @return  self  The narrowed budget.
     *
     * @throws  InvalidArgumentException  When an override widens a bound or falls outside its range.
     *
     * @since   2.0.0
     */
    public function narrowed(?int $timeBudgetSeconds, ?int $maximumBatch, ?int $maximumBatches): self
    {
        if (
            ($timeBudgetSeconds !== null && $timeBudgetSeconds > $this->timeBudgetSeconds)
            || ($maximumBatch !== null && $maximumBatch > $this->maximumBatch)
            || ($maximumBatches !== null && $maximumBatches > $this->maximumBatches)
        ) {
            throw new InvalidArgumentException('A retention payload cannot widen the declared budget.');
        }
        $ceiling = $maximumBatch ?? $this->maximumBatch;

        return new self(
            $timeBudgetSeconds ?? $this->timeBudgetSeconds,
            min($this->minimumBatch, $ceiling),
            $ceiling,
            $this->lockBudgetMilliseconds,
            $maximumBatches ?? $this->maximumBatches,
        );
    }

    /**
     * The batch size a run starts with.
     *
     * @return  int  The declared minimum, so a cold store is probed before it is hit.
     *
     * @since   2.0.0
     */
    public function initialBatch(): int
    {
        return $this->minimumBatch;
    }

    /**
     * Size the next batch from how long the last one took.
     *
     * @param   int    $lastBatch              Rows the last batch asked for.
     * @param   float  $lastBatchMilliseconds  Wall time the last batch's transaction took.
     *
     * @return  int  Doubled when the last batch used under half the lock budget, halved when it overran
     *          the lock budget, unchanged otherwise, and always inside the declared bounds.
     *
     * @since   2.0.0
     */
    public function nextBatch(int $lastBatch, float $lastBatchMilliseconds): int
    {
        $next = $lastBatch;
        if ($lastBatchMilliseconds < $this->lockBudgetMilliseconds / 2) {
            $next = $lastBatch * 2;
        } elseif ($lastBatchMilliseconds > $this->lockBudgetMilliseconds) {
            $next = intdiv($lastBatch, 2);
        }

        return max($this->minimumBatch, min($this->maximumBatch, $next));
    }
}
