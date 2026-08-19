<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Domain;

/**
 * Journal state of one schema-plan step, as persisted on that step's row.
 *
 * The executor writes this value before and after every attempt, and `SchemaPlanStep` decides which
 * evidence each state is allowed to carry: a pending step holds no execution state at all, an attempted
 * step must name its fence, start time, and prior checksum, and a terminal step must carry a completion
 * time. That is what lets an interrupted execution be resumed from the journal rather than replayed
 * from the beginning against a database that has already moved.
 *
 * @since  2.0.0
 */
enum SchemaStepStatus: string
{
    /**
     * Journaled alongside the plan but never attempted, so it carries no fence, cursor, or timestamps.
     *
     * @since  2.0.0
     */
    case Pending = 'pending';

    /**
     * An attempt holds the step under an execution fence and its result is not yet known.
     *
     * @since  2.0.0
     */
    case Running = 'running';

    /**
     * The operation was applied and the schema checksum it produced was recorded on the step.
     *
     * @since  2.0.0
     */
    case Completed = 'completed';

    /**
     * An attempt ended with a recorded error code, leaving the step open to a further attempt.
     *
     * @since  2.0.0
     */
    case Failed = 'failed';

    /**
     * The step's effect was undone deliberately, so the plan owes nothing further for it.
     *
     * @since  2.0.0
     */
    case Compensated = 'compensated';

    /**
     * The step was passed over rather than executed, and the plan may finish without it.
     *
     * @since  2.0.0
     */
    case Skipped = 'skipped';

    /**
     * Report whether the step is settled and no further attempt is owed for it.
     *
     * `Failed` is deliberately not settled: it is the state operator recovery restarts from, so it has
     * to remain distinguishable from a step that genuinely needs nothing more.
     *
     * @return  bool  True for completed, compensated, and skipped steps only.
     *
     * @since   2.0.0
     */
    public function terminal(): bool
    {
        return in_array($this, [self::Completed, self::Compensated, self::Skipped], true);
    }
}
