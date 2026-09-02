<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

/**
 * Outcome of a single migration run: the migrations this invocation recorded as applied.
 *
 * `MigrationRunner` skips everything already in the ledger, so the list names what advanced the ledger
 * here rather than the whole plan. On MySQL a migration whose interrupted attempt was reconciled
 * instead of re-run is still listed, because the ledger moved even though no DDL did. `MigrateCommand`
 * prints the IDs and uses `changed()` to report whether the run advanced the schema or found it
 * already current. The tables the pass then converged on the database's default collation are
 * carried beside the IDs, so the command can say which physical tables were rewritten and to what.
 *
 * @since  2.0.0
 */
final readonly class MigrationResult
{
    /**
     * Capture the migrations a single run recorded as applied and the tables it converged afterwards.
     *
     * @param  list<string>  $applied    IDs of the migrations this run recorded, in plan order.
     * @param  list<string>  $converged  Physical names of the tables `SchemaCollationConvergence` converted
     *         after the plan, in name order; empty when every table already carried the database default.
     * @param  ?string       $collation  The database default collation the converged tables now carry; null
     *         when nothing was converted or the platform has no collation to converge on.
     *
     * @since  2.0.0
     */
    public function __construct(
        public array $applied,
        public array $converged = [],
        public ?string $collation = null,
    ) {
    }

    /**
     * Reports whether this run advanced the schema at all.
     *
     * @return  bool  False when the ledger was already current and no migration ran.
     *
     * @since   2.0.0
     */
    public function changed(): bool
    {
        return $this->applied !== [];
    }
}
