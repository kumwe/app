<?php

declare(strict_types=1);

namespace Kumwe\App\Tools;

/**
 * Decides whether two breakpoint ramps reproduce the same material result.
 *
 * A shared runner can add a small absolute delay to one of the three samples a breakpoint size
 * measures. Since p95 is then the maximum observation, that delay can be a large relative change
 * while remaining immaterial against the interpolated objective. Knee agreement stays absolute;
 * elsewhere a divergence is material only when it breaches both the relative tolerance and ten
 * percent of the declared budget.
 *
 * @since  2.0.0
 */
final class PerfBreakpointStability
{
    private const float RELATIVE_DELTA_LIMIT = 0.35;

    private const float BUDGET_DELTA_LIMIT = 0.10;

    /**
     * Compare two ramp knees and their p95 pairs.
     *
     * @param   int|null                                                                                     $firstKnee
     *     First line count to cross its objective, or null when none did.
     * @param   int|null                                                                                     $secondKnee
     *     Second line count to cross its objective, or null when none did.
     * @param   list<array{first_p95_ms: float, second_p95_ms: float, budget_p95_ms: float}>                  $pairs
     *     Corresponding p95 observations and their interpolated budgets.
     *
     * @return  bool  True when both passes reproduce the same material breakpoint result.
     *
     * @since   2.0.0
     */
    public static function agrees(?int $firstKnee, ?int $secondKnee, array $pairs): bool
    {
        if ($firstKnee !== $secondKnee) {
            return false;
        }

        foreach ($pairs as $pair) {
            $first = $pair['first_p95_ms'];
            $second = $pair['second_p95_ms'];
            $delta = abs($first - $second);
            $reference = max($first, $second, 0.001);
            $budget = max($pair['budget_p95_ms'], 0.001);

            if (
                $delta / $reference > self::RELATIVE_DELTA_LIMIT
                && $delta / $budget > self::BUDGET_DELTA_LIMIT
            ) {
                return false;
            }
        }

        return true;
    }
}
