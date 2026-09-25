<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Retention;

use DateTimeImmutable;

/**
 * The six retention metrics of one store at one instant, with the honesty flags that qualify them.
 *
 * Every rate is rows per second. Ingest is what arrived over the observer's trailing window, expiry is
 * what will become eligible over the observer's leading window, and drain is what the last scheduled
 * run removed per second of its own run time. Backlog is the number of rows already eligible and
 * still present; because it is read with a bounded probe rather than an exact count, `backlogApproximate`
 * says when the probe hit its cap and the true figure is larger. The forecast is the time until the
 * backlog reaches the policy's capacity at the current net slope, or null when the slope is not
 * positive. `configured` and `settingProblems` carry the readiness clause: whether every setting the
 * policy requires is present, and which are not.
 *
 * @since  2.0.0
 */
final readonly class RetentionObservation
{
    /**
     * Record one observation.
     *
     * @param  RetentionStore      $store                      Store observed.
     * @param  DateTimeImmutable   $observedAt                 Instant every age and window is measured against.
     * @param  ?float              $ingestRowsPerSecond        Arrival rate over the trailing window, or null when
     *         the store has no indexed arrival time to probe.
     * @param  ?float              $expiryRowsPerSecond        Rate at which rows become eligible over the leading
     *         window, or null when the store is never drained.
     * @param  ?float              $drainRowsPerSecond         Removal rate of the last recorded run, or null when
     *         no run has been recorded.
     * @param  int                 $backlogRows                Eligible rows still present, capped by the probe.
     * @param  bool                $backlogApproximate         True when the probe hit its cap.
     * @param  ?float              $oldestEligibleAgeSeconds   Age of the oldest eligible row, or null when none.
     * @param  ?float              $forecastSecondsToCapacity  Seconds until the backlog reaches capacity at the
     *         current net slope, or null when no exhaustion is predicted.
     * @param  int                 $capacityRows               Backlog at which the store counts as exhausted.
     * @param  bool                $configured                 True when every required setting is present.
     * @param  list<string>        $settingProblems            Required settings that are absent or disabled.
     * @param  ?DateTimeImmutable  $lastDrainAt                When the last recorded run finished, or null.
     *
     * @since  2.0.0
     */
    public function __construct(
        public RetentionStore $store,
        public DateTimeImmutable $observedAt,
        public ?float $ingestRowsPerSecond,
        public ?float $expiryRowsPerSecond,
        public ?float $drainRowsPerSecond,
        public int $backlogRows,
        public bool $backlogApproximate,
        public ?float $oldestEligibleAgeSeconds,
        public ?float $forecastSecondsToCapacity,
        public int $capacityRows,
        public bool $configured,
        public array $settingProblems,
        public ?DateTimeImmutable $lastDrainAt,
    ) {
    }

    /**
     * Compute the forecast from a backlog and the two rates that move it.
     *
     * The net slope is ingest minus the sustained drain capacity, where the sustained capacity is the
     * run rate scaled by the schedule's duty cycle. A null ingest means the slope is unknown and no
     * forecast is made; a slope at or below zero means the backlog is shrinking or stable.
     *
     * @param   int     $backlogRows          Eligible rows still present.
     * @param   int     $capacityRows         Backlog at which the store counts as exhausted.
     * @param   ?float  $ingestRowsPerSecond  Arrival rate, or null when unknown.
     * @param   ?float  $drainRowsPerSecond   Run removal rate, or null when no run is recorded.
     * @param   float   $dutyCycle            Fraction of wall time the schedule lets the drain run.
     *
     * @return  ?float  Seconds until capacity, or null when no exhaustion is predicted or the slope is unknown.
     *
     * @since   2.0.0
     */
    public static function forecast(
        int $backlogRows,
        int $capacityRows,
        ?float $ingestRowsPerSecond,
        ?float $drainRowsPerSecond,
        float $dutyCycle,
    ): ?float {
        if ($ingestRowsPerSecond === null) {
            return null;
        }
        $slope = $ingestRowsPerSecond - ($drainRowsPerSecond ?? 0.0) * $dutyCycle;
        if ($slope <= 0.0) {
            return null;
        }

        return max(0.0, ($capacityRows - $backlogRows) / $slope);
    }
}
