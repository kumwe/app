<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Domain;

/**
 * Impact class of a schema operation, and the approval ceremony the plan containing it inherits.
 *
 * Every operation declares one of these, and a plan's risk must equal the highest its operations carry,
 * so one destructive step raises the whole plan. The class is what the approval path reads: it decides
 * whether an approver has to supply a high-impact confirmation, whether tested recovery evidence bound
 * to the source schema must exist and still be fresh, and — for the destructive class — whether a
 * separate authorization is demanded again at execution time.
 *
 * @since  2.0.0
 */
enum SchemaRisk: string
{
    /**
     * Adds structure only, without rewriting stored rows or blocking concurrent traffic.
     *
     * @since  2.0.0
     */
    case OnlineSafeAdditive = 'online_safe_additive';

    /**
     * Rewrites existing rows rather than shape, so it runs in chunks and scales with the data.
     *
     * @since  2.0.0
     */
    case BackfillRequired = 'backfill_required';

    /**
     * Rebuilds a table or holds a lock, so ordinary traffic stalls for the duration.
     *
     * @since  2.0.0
     */
    case RebuildOrLocking = 'rebuild_or_locking';

    /**
     * Leaves stored values in place but changes how they are constrained or interpreted.
     *
     * @since  2.0.0
     */
    case BehaviorChanging = 'behavior_changing';

    /**
     * Removes structure or data that the schema itself can no longer reproduce.
     *
     * @since  2.0.0
     */
    case Destructive = 'destructive';

    /**
     * Rank this class on the single scale plan risk is compared by.
     *
     * The scale is not declaration order: a rebuild or locking change outranks a behaviour change,
     * because the stall it imposes is the harder thing for an operator to schedule around.
     *
     * @return  int  Zero for an online-safe addition, rising to four for a destructive change.
     *
     * @since   2.0.0
     */
    public function severity(): int
    {
        return match ($this) {
            self::OnlineSafeAdditive => 0,
            self::BackfillRequired => 1,
            self::BehaviorChanging => 2,
            self::RebuildOrLocking => 3,
            self::Destructive => 4,
        };
    }

    /**
     * Report whether approving a plan at this class demands the high-impact confirmation.
     *
     * @return  bool  True for every class except an online-safe addition.
     *
     * @since   2.0.0
     */
    public function requiresHighImpactAuthorization(): bool
    {
        return $this !== self::OnlineSafeAdditive;
    }

    /**
     * Report whether a plan at this class may only proceed against a tested restore drill.
     *
     * @return  bool  True for rebuild-or-locking and destructive changes, which are the two classes a
     *          failed execution cannot be talked out of without a backup.
     *
     * @since   2.0.0
     */
    public function requiresRecoveryEvidence(): bool
    {
        return in_array($this, [self::RebuildOrLocking, self::Destructive], true);
    }

    /**
     * Reduce the risks of a plan's operations to the one class that classifies the plan.
     *
     * @param   iterable<self>  $risks  Risk declared by each operation, in any order.
     *
     * @return  self  The most severe class present; an online-safe addition when nothing was supplied.
     *
     * @since   2.0.0
     */
    public static function highest(iterable $risks): self
    {
        $highest = self::OnlineSafeAdditive;
        foreach ($risks as $risk) {
            if ($risk->severity() > $highest->severity()) {
                $highest = $risk;
            }
        }

        return $highest;
    }
}
