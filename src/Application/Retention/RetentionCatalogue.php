<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Retention;

use InvalidArgumentException;

/**
 * The declared retention contract of every hot store, sized from the capacity contract.
 *
 * `docs/roadmap/capacity-contract.json` fixes the enterprise peak at 463 logical writes a second and
 * requires maintenance to sustain at least twice the peak expiry rate, so every drainable ledger here
 * declares a required drain of 926 rows a second. The declared time budget and schedule interval give
 * each drain a duty cycle of one half, so a run has to remove rows at twice that rate while it runs.
 * Whether an installation actually meets the figure is measured by `tools/perf-retention.php` and
 * observed continuously through `RetentionObserver`; the catalogue only states what is required.
 *
 * The catalogue is code rather than configuration because a missing declaration must fail the build,
 * not the night shift: `RetentionCatalogueTest` asserts that every `RetentionStore` case has an entry
 * and that every entry carries each clause the contract names.
 *
 * @since  2.0.0
 */
final readonly class RetentionCatalogue
{
    /**
     * Enterprise peak logical writes a second, from `profiles.enterprise.peak_logical_writes_per_second`.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int PEAK_LOGICAL_WRITES_PER_SECOND = 463;

    /**
     * Required multiple of the peak expiry rate, from `retention_budget_rule`.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int DRAIN_MULTIPLE_OF_PEAK_EXPIRY = 2;

    /**
     * Sustained removal rate every drainable ledger must reach: the peak times the multiple.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int REQUIRED_DRAIN_ROWS_PER_SECOND = self::PEAK_LOGICAL_WRITES_PER_SECOND
        * self::DRAIN_MULTIPLE_OF_PEAK_EXPIRY;

    /**
     * Retained rows per ledger the envelope plans for, from `data_volume.retained_ledger_rows`.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int LEDGER_CAPACITY_ROWS = 100_000_000;

    /**
     * Job type of the generic scheduled drain that serves the stores without a dedicated purge job.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string DRAIN_JOB_TYPE = 'system.retention.drain';

    /**
     * Wall-clock budget one scheduled drain run may spend.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int TIME_BUDGET_SECONDS = 30;

    /**
     * Interval of the every-minute maintenance schedules, giving the drains a duty cycle of one half.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int SCHEDULE_INTERVAL_SECONDS = 60;

    /**
     * Longest a single batch transaction should hold its locks against live traffic.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int LOCK_BUDGET_MILLISECONDS = 250;

    /**
     * Bind the catalogue to its declarations.
     *
     * @param   array<string, RetentionPolicy>  $policies  Declarations keyed by store value.
     *
     * @throws  InvalidArgumentException  When a store is declared twice or a declaration names another store.
     *
     * @since   2.0.0
     */
    public function __construct(private array $policies)
    {
        foreach ($policies as $key => $policy) {
            if ($policy->store->value !== $key) {
                throw new InvalidArgumentException('A retention catalogue entry is keyed by the wrong store.');
            }
        }
    }

    /**
     * Build the shipped catalogue.
     *
     * @return  self  One declaration per `RetentionStore` case.
     *
     * @since   2.0.0
     */
    public static function declared(): self
    {
        $policies = [];
        foreach (self::declarations() as $policy) {
            $policies[$policy->store->value] = $policy;
        }

        return new self($policies);
    }

    /**
     * Read one store's declaration.
     *
     * @param   RetentionStore  $store  Store to look up.
     *
     * @return  RetentionPolicy  Its declaration.
     *
     * @throws  InvalidArgumentException  When the store is not declared.
     *
     * @since   2.0.0
     */
    public function policy(RetentionStore $store): RetentionPolicy
    {
        return $this->policies[$store->value]
            ?? throw new InvalidArgumentException(sprintf('The %s store has no retention declaration.', $store->value));
    }

    /**
     * Read every declaration in catalogue order.
     *
     * @return  list<RetentionPolicy>  Every declared policy.
     *
     * @since   2.0.0
     */
    public function policies(): array
    {
        return array_values($this->policies);
    }

    /**
     * Declare every store.
     *
     * @return  list<RetentionPolicy>  The shipped declarations.
     *
     * @since   2.0.0
     */
    private static function declarations(): array
    {
        $ledger = self::LEDGER_CAPACITY_ROWS;
        $backup = 'Included in every full snapshot; a restore reinstates the rows exactly as retained at snapshot '
            . 'time and the next scheduled drain resumes from the restored state.';
        $hold = 'Disable the drain schedule for the duration of the hold; rows accumulate and the forecast metric '
            . 'reports the resulting exhaustion date.';
        $failure = 'The failed job is retried under its attempt budget and dead-lettered after it; the backlog and '
            . 'oldest-age metrics keep rising until an operator re-enables or repairs the drain.';

        return [
            new RetentionPolicy(
                RetentionStore::BusinessIdempotency,
                'Replays a retried typed record command with its stored outcome and refuses a late repeat by name.',
                0,
                false,
                'indexed_expiry_column',
                ['in_progress', 'completed', 'expired', 'removed'],
                200,
                1_000,
                self::TIME_BUDGET_SECONDS,
                self::LOCK_BUDGET_MILLISECONDS,
                self::SCHEDULE_INTERVAL_SECONDS,
                self::REQUIRED_DRAIN_ROWS_PER_SECOND,
                $ledger,
                'business.record.idempotency.purge',
                ['schedule:business.record.idempotency.purge'],
                $backup,
                $hold,
                $failure,
                'A claim that outlives its retention is harmless until removed; no reconciliation is needed beyond '
                . 'confirming the drain schedule is enabled and its runs are recorded.',
            ),
            new RetentionPolicy(
                RetentionStore::DeliveryIdempotency,
                'Replays a retried keyed HTTP or machine mutation with its stored response.',
                0,
                false,
                'indexed_expiry_column',
                ['in_progress', 'completed', 'failed', 'expired', 'removed'],
                200,
                10_000,
                self::TIME_BUDGET_SECONDS,
                self::LOCK_BUDGET_MILLISECONDS,
                self::SCHEDULE_INTERVAL_SECONDS,
                self::REQUIRED_DRAIN_ROWS_PER_SECOND,
                $ledger,
                'system.idempotency.purge',
                ['schedule:system.idempotency.purge'],
                $backup,
                $hold,
                $failure,
                'A record re-owned between the candidate read and the delete survives by design; no reconciliation '
                . 'is needed beyond confirming the drain schedule is enabled and its runs are recorded.',
            ),
            new RetentionPolicy(
                RetentionStore::Revisions,
                'Authoritative history of every business record version and its owned lines.',
                PHP_INT_MAX,
                true,
                'none',
                ['retained'],
                1,
                1,
                self::TIME_BUDGET_SECONDS,
                self::LOCK_BUDGET_MILLISECONDS,
                self::SCHEDULE_INTERVAL_SECONDS,
                0,
                $ledger,
                null,
                [],
                'Included in every full snapshot as product data.',
                'Revisions are never drained, so a hold changes nothing.',
                'Not applicable: there is no drain to fail.',
                'Growth is forecast against capacity from the ingest metric alone; exceeding capacity is a storage '
                . 'planning event handled through the storage forecast, never by deletion.',
            ),
            new RetentionPolicy(
                RetentionStore::OutboxSourceEvents,
                'Committed source of every durable integration event until dispatched and retained for replay.',
                90 * 86_400,
                false,
                'indexed_expiry_column',
                ['pending', 'reserved', 'dispatched', 'dead', 'removed'],
                200,
                10_000,
                self::TIME_BUDGET_SECONDS,
                self::LOCK_BUDGET_MILLISECONDS,
                self::SCHEDULE_INTERVAL_SECONDS,
                self::REQUIRED_DRAIN_ROWS_PER_SECOND,
                $ledger,
                self::DRAIN_JOB_TYPE,
                ['schedule:' . self::DRAIN_JOB_TYPE . ':outbox_source_events'],
                $backup,
                $hold,
                $failure,
                'Only rows the sequencer has already journaled are removed; a row still staged is kept regardless '
                . 'of age so a late sequencer cannot lose it.',
            ),
            new RetentionPolicy(
                RetentionStore::SequencedJournal,
                'Ordered projection source that dispatchers and projections consume by checkpoint.',
                90 * 86_400,
                true,
                'checkpoint_bounded',
                ['sequenced', 'consumed', 'removed'],
                200,
                5_000,
                self::TIME_BUDGET_SECONDS,
                self::LOCK_BUDGET_MILLISECONDS,
                self::SCHEDULE_INTERVAL_SECONDS,
                self::REQUIRED_DRAIN_ROWS_PER_SECOND,
                $ledger,
                self::DRAIN_JOB_TYPE,
                ['schedule:' . self::DRAIN_JOB_TYPE . ':sequenced_journal'],
                'Included in every full snapshot together with the journal head; a projection rebuild replays only '
                . 'the retained window, so older history comes from a restored snapshot.',
                $hold,
                $failure,
                'Rows are removed only below every live projection checkpoint and only after their outbox row is '
                . 'gone, so a lagging projection or an operator replay always finds its source.',
            ),
            new RetentionPolicy(
                RetentionStore::InboxReceipts,
                'Independent per-consumer delivery receipts and the deduplication tombstones they leave.',
                90 * 86_400,
                false,
                'terminal_status_age',
                ['pending', 'reserved', 'completed', 'poison', 'compacted', 'removed'],
                200,
                5_000,
                self::TIME_BUDGET_SECONDS,
                self::LOCK_BUDGET_MILLISECONDS,
                self::SCHEDULE_INTERVAL_SECONDS,
                self::REQUIRED_DRAIN_ROWS_PER_SECOND,
                $ledger,
                self::DRAIN_JOB_TYPE,
                ['schedule:' . self::DRAIN_JOB_TYPE . ':inbox_receipts'],
                $backup,
                $hold,
                $failure,
                'A tombstone is removed only once its outbox row is gone, so no replay can redeliver an event whose '
                . 'receipt no longer exists.',
            ),
            new RetentionPolicy(
                RetentionStore::JobHistory,
                'Settled queue jobs, their attempt counts and the dead-letter ledger.',
                7 * 86_400,
                false,
                'terminal_status_age',
                ['pending', 'reserved', 'completed', 'dead', 'removed'],
                200,
                5_000,
                self::TIME_BUDGET_SECONDS,
                self::LOCK_BUDGET_MILLISECONDS,
                self::SCHEDULE_INTERVAL_SECONDS,
                self::REQUIRED_DRAIN_ROWS_PER_SECOND,
                $ledger,
                self::DRAIN_JOB_TYPE,
                ['schedule:' . self::DRAIN_JOB_TYPE . ':job_history'],
                $backup,
                $hold,
                $failure,
                'Contributed queues keep their signed retention days and are drained by the queue runtime '
                . 'operations; this drain covers the core queues only, so no job is subject to two windows.',
            ),
            new RetentionPolicy(
                RetentionStore::ProcessHistory,
                'Settled work items of long-running processes.',
                30 * 86_400,
                false,
                'terminal_status_age',
                ['pending', 'reserved', 'completed', 'dead', 'removed'],
                200,
                5_000,
                self::TIME_BUDGET_SECONDS,
                self::LOCK_BUDGET_MILLISECONDS,
                self::SCHEDULE_INTERVAL_SECONDS,
                self::REQUIRED_DRAIN_ROWS_PER_SECOND,
                $ledger,
                self::DRAIN_JOB_TYPE,
                ['schedule:' . self::DRAIN_JOB_TYPE . ':process_history'],
                $backup,
                $hold,
                $failure,
                'Process instances are never removed by this drain; only settled work items go, so a process '
                . 'remains inspectable after its history is compacted.',
            ),
            new RetentionPolicy(
                RetentionStore::ExportArtifacts,
                'Downloadable report exports until their declared expiry.',
                0,
                false,
                'indexed_expiry_column',
                ['queued', 'running', 'completed', 'expired', 'removed'],
                50,
                500,
                self::TIME_BUDGET_SECONDS,
                self::LOCK_BUDGET_MILLISECONDS,
                self::SCHEDULE_INTERVAL_SECONDS,
                self::REQUIRED_DRAIN_ROWS_PER_SECOND,
                1_000_000,
                self::DRAIN_JOB_TYPE,
                ['schedule:' . self::DRAIN_JOB_TYPE . ':export_artifacts'],
                'Artifact objects live outside the database and are not part of the snapshot; a restored row whose '
                . 'object is missing reports the artifact unavailable.',
                $hold,
                $failure,
                'The row is removed only after its object is deleted or already absent, so storage cannot leak.',
            ),
            new RetentionPolicy(
                RetentionStore::Audit,
                'Tamper-evident record of every actor, action, target and outcome.',
                0,
                true,
                'anchored_range',
                ['recorded', 'anchored', 'archived', 'pruned'],
                1,
                1,
                self::TIME_BUDGET_SECONDS,
                self::LOCK_BUDGET_MILLISECONDS,
                86_400,
                self::REQUIRED_DRAIN_ROWS_PER_SECOND,
                $ledger,
                'audit.retention.enforce',
                ['schedule:audit.retention.enforce', 'payload:audit.retention.enforce:retention_days'],
                'Rows are included in every full snapshot; archives under storage/private/audit-archives must be '
                . 'copied off-host by the backup cycle and proven restorable before a range is pruned.',
                'Set retention_days to zero or disable the schedule; anchoring and verification continue unchanged.',
                'A pass that cannot export, verify or chain its prune mark rolls back entirely and leaves the trail '
                . 'intact; the failed job is retried and dead-lettered under its attempt budget.',
                'Run audit:verify: the anchor ledger names every pruned range with its row count, rolling digest and '
                . 'archive checksum, so a pruned range is reconciled against its archive by checksum.',
            ),
            new RetentionPolicy(
                RetentionStore::Sessions,
                'Live administrator and portal sessions, step-up proofs and issued tokens until they expire.',
                0,
                false,
                'indexed_expiry_column',
                ['active', 'expired', 'removed'],
                100,
                1_000,
                self::TIME_BUDGET_SECONDS,
                self::LOCK_BUDGET_MILLISECONDS,
                900,
                self::REQUIRED_DRAIN_ROWS_PER_SECOND,
                10_000_000,
                'system.sessions.purge',
                ['schedule:system.sessions.purge'],
                $backup,
                'Sessions are security state; a hold does not apply.',
                $failure,
                'An expired session is refused on use regardless of whether it has been removed.',
            ),
        ];
    }
}
