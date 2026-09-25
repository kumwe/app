<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Retention;

use InvalidArgumentException;

/**
 * Everything the capacity contract requires one hot store to declare about its own retention.
 *
 * A policy is a declaration, not a mechanism: it says what the store is for, how long its rows must
 * survive, whether they may ever be rewritten, how eligibility for removal is found without a table
 * scan, which states a row moves through, how much of a transaction, a lock, a wall clock and a replica
 * a drain may spend, which schedule setting has to exist for the store to be considered configured, how
 * backups and legal holds treat it, and what an operator does when a drain fails or the ledger and its
 * archive disagree. `RetentionCatalogue` holds one for every `RetentionStore`, and the drain, the
 * observer and the readiness assessment read their numbers from here rather than carrying their own.
 *
 * @since  2.0.0
 */
final readonly class RetentionPolicy
{
    /**
     * Declare one store's retention contract.
     *
     * @param   RetentionStore  $store                       Store the declaration governs.
     * @param   string          $purpose                     What the rows are authoritative for while retained.
     * @param   int             $minimumRetentionSeconds     Shortest time a row must survive after it settles;
     *          zero when rows may go as soon as they expire, `PHP_INT_MAX` when they are kept for good.
     * @param   bool            $immutable                   True when a retained row is never rewritten in
     *          place, so removal is the only mutation the store ever sees.
     * @param   string          $expiryStrategy              How eligible rows are found: an indexed expiry
     *          column, a terminal status plus an indexed settlement time, an anchored position range, a
     *          checkpoint-bounded sequence, or `none` for a store that is never drained.
     * @param   list<string>    $states                      Row states in lifecycle order, from hot to gone.
     * @param   int             $minimumBatch                Smallest batch a drain issues, at least one.
     * @param   int             $maximumBatch                Largest batch the store's delete accepts.
     * @param   int             $timeBudgetSeconds           Wall-clock budget one scheduled drain run may spend.
     * @param   int             $lockBudgetMilliseconds      Longest one batch transaction should hold its
     *          locks; the adaptive sizer shrinks the batch when a batch exceeds it.
     * @param   int             $scheduleIntervalSeconds     How often the drain is scheduled to run.
     * @param   int             $requiredDrainRowsPerSecond  Sustained removal rate the contract requires,
     *          which is at least twice the peak expiry rate.
     * @param   int             $capacityRows                Backlog at which the store is treated as exhausted
     *          for the forecast metric.
     * @param   ?string         $drainJobType                Job type whose schedule drains the store, or null
     *          when the store is retained for good or drained by another mechanism.
     * @param   list<string>    $requiredSettings            Settings that must be present and positive for the
     *          store to count as configured under the enterprise profile.
     * @param   string          $backupBehaviour             How backups and restores treat the rows.
     * @param   string          $legalHoldBehaviour          What a legal hold does to the drain.
     * @param   string          $failureProcedure            What an operator does when a drain fails.
     * @param   string          $reconciliationProcedure     How the ledger is reconciled with its archive or
     *          its consumers after a failure.
     *
     * @throws  InvalidArgumentException  When a batch bound, budget, rate or capacity is not positive or the
     *          batch bounds are inverted.
     *
     * @since   2.0.0
     */
    public function __construct(
        public RetentionStore $store,
        public string $purpose,
        public int $minimumRetentionSeconds,
        public bool $immutable,
        public string $expiryStrategy,
        public array $states,
        public int $minimumBatch,
        public int $maximumBatch,
        public int $timeBudgetSeconds,
        public int $lockBudgetMilliseconds,
        public int $scheduleIntervalSeconds,
        public int $requiredDrainRowsPerSecond,
        public int $capacityRows,
        public ?string $drainJobType,
        public array $requiredSettings,
        public string $backupBehaviour,
        public string $legalHoldBehaviour,
        public string $failureProcedure,
        public string $reconciliationProcedure,
    ) {
        if (
            $minimumBatch < 1 || $maximumBatch < $minimumBatch || $timeBudgetSeconds < 1
            || $lockBudgetMilliseconds < 1 || $scheduleIntervalSeconds < 1 || $requiredDrainRowsPerSecond < 0
            || $capacityRows < 1 || $minimumRetentionSeconds < 0 || $states === []
        ) {
            throw new InvalidArgumentException('A retention policy declares an invalid bound.');
        }
    }

    /**
     * Whether the store is ever drained at all.
     *
     * @return  bool  False for a store whose rows are retained for the life of the installation.
     *
     * @since   2.0.0
     */
    public function drainable(): bool
    {
        return $this->expiryStrategy !== 'none' && $this->minimumRetentionSeconds !== PHP_INT_MAX;
    }

    /**
     * The budget a scheduled drain of this store starts from before any payload override.
     *
     * @return  RetentionBudget  Time, batch and lock bounds as declared.
     *
     * @since   2.0.0
     */
    public function budget(): RetentionBudget
    {
        return new RetentionBudget(
            $this->timeBudgetSeconds,
            $this->minimumBatch,
            $this->maximumBatch,
            $this->lockBudgetMilliseconds,
        );
    }

    /**
     * Fraction of wall time the schedule lets the drain run, which scales an observed run rate to a
     * sustained one.
     *
     * @return  float  Between zero and one.
     *
     * @since   2.0.0
     */
    public function dutyCycle(): float
    {
        return min(1.0, $this->timeBudgetSeconds / $this->scheduleIntervalSeconds);
    }
}
