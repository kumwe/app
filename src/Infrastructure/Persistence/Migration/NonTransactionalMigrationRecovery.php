<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

/**
 * Crash-recovery journal for migrations on database platforms whose DDL commits implicitly.
 *
 * On PostgreSQL a migration and its ledger row share one transaction, so a run killed part way leaves
 * nothing behind. MySQL commits DDL as it executes, so the same interruption can leave half a schema
 * change with no ledger row to account for it. An implementation records an attempt before the
 * migration starts and retires it once the ledger row exists, which is what lets a later run tell an
 * interrupted migration from a fresh one and resume only where the migration's own strategy proves a
 * replay safe. Anything else fails closed rather than guessing whether committed DDL can be repeated,
 * and `ReadinessProbe` keeps an instance with an unresolved attempt out of service until an operator
 * has dealt with it.
 *
 * @since  2.0.0
 */
interface NonTransactionalMigrationRecovery
{
    /**
     * Refuse to continue when the journal holds an attempt for a migration this binary does not ship.
     *
     * `MigrationRunner` calls this before migrating and before listing pending work, so a rollback onto
     * an older build stops rather than stepping over a recovery it cannot reason about.
     *
     * @param   list<string>  $knownMigrationIds  IDs of every migration in the deployed plan.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When a journaled attempt names a migration outside that list, or the
     *          journal itself is unreadable.
     *
     * @since   2.0.0
     */
    public function assertKnownAttempts(array $knownMigrationIds): void;

    /**
     * Report whether any journaled attempt is still open.
     *
     * `ReadinessProbe` gates on this, so an instance whose last migration pass was interrupted stays
     * unready even once its ledger covers the whole plan.
     *
     * @return  bool  True while at least one attempt is recorded and has not been retired.
     *
     * @throws  \RuntimeException  When the journal exists but cannot be trusted to answer, because its
     *          own schema has diverged from the one recovery writes.
     *
     * @since   2.0.0
     */
    public function hasUnresolvedAttempts(): bool;

    /**
     * Journal the attempt about to start, and decide how an interrupted earlier one resumes.
     *
     * Called immediately before `up()`. A first attempt is recorded and executed; a repeat attempt may
     * continue only along the strategy the first one registered, which either replays `up()` or finds
     * the work already restored. An implementation refuses while another migration's attempt is still
     * open, when the journaled checksum or strategy no longer matches the code, or when the migration
     * offers no proven way to repeat itself.
     *
     * @param   Migration  $migration  Migration whose attempt is being opened or resumed.
     *
     * @return  NonTransactionalMigrationAction  Whether the runner must run `up()` or only record the
     *          ledger row.
     *
     * @throws  \RuntimeException  When another attempt is unresolved, the journaled checksum or strategy
     *          has drifted, or the interrupted migration is not repeatable.
     *
     * @since   2.0.0
     */
    public function prepare(Migration $migration): NonTransactionalMigrationAction;

    /**
     * Retire the attempt now that the migration's ledger row has been written.
     *
     * The runner calls this after the ledger write, so the journal is empty again for the next
     * migration. A crash between the two leaves an attempt behind that a later run clears through
     * `reconcileApplied()` rather than replaying.
     *
     * @param   Migration  $migration  Migration whose attempt is being closed.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When no attempt is journaled for the migration, its checksum has
     *          drifted, or the row could not be removed.
     *
     * @since   2.0.0
     */
    public function complete(Migration $migration): void;

    /**
     * Clear a leftover attempt for a migration the ledger already records as applied.
     *
     * This closes the window between the ledger write and `complete()`: the migration did finish, so
     * the stale row is removed instead of driving a replay, and readiness stops reporting an unresolved
     * attempt. It does nothing when no attempt is journaled, which is the usual case.
     *
     * @param   Migration  $migration  Applied migration whose stale attempt is being cleared.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When the journaled checksum does not match the migration or the row
     *          could not be removed.
     *
     * @since   2.0.0
     */
    public function reconcileApplied(Migration $migration): void;
}
