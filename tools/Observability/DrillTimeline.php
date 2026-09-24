<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

/**
 * Turns what an alert drill really observed into a promtool timeline.
 *
 * A drill cannot wait five minutes for every `for:` clause, let alone three hours for a stale backup, so it
 * observes the real system in ticks and places tick *n* at *n* evaluation intervals on a synthetic clock. What
 * is replayed is exactly what was scraped: gauges and counters keep their real values, a series that vanishes
 * from a scrape gets a staleness marker as Prometheus would write one, and a failed scrape becomes `up == 0`.
 *
 * Two things are adapted to the synthetic clock, and only these:
 *
 * - **Holds.** `hold()` lets synthetic time pass with nothing new observed. It is how "no backup ran for three
 *   hours" is expressed; the held values are the last real scrape repeated, never invented values.
 * - **Timestamps.** A `*_timestamp_seconds` value is a wall-clock instant. It is moved onto the synthetic clock
 *   by the same piecewise mapping for every series and every tick: each observation, and the end of each hold,
 *   anchors a real instant to a tick, and an instant that happened *d* real seconds after the latest anchor
 *   lands *d* synthetic seconds after that tick (never at or past the next anchored tick). Ordering between
 *   recorded instants and their age at each observation are preserved, and something done after a hold is
 *   placed after it. Zero, the "never recorded" value, is mapped like any instant and so lands about
 *   fifty-six years before the first tick: as old as it really is, which is what the rules assume.
 *
 * Checkpoints record, at the current tick, whether the drill's alert must be firing; the evaluator turns them
 * into `alert_rule_test` cases.
 *
 * @since  2.0.0
 */
final class DrillTimeline
{
    /**
     * Name suffix of gauges whose value is a Unix timestamp and so is mapped onto the synthetic clock.
     *
     * @var    string
     * @since  2.0.0
     */
    public const TIMESTAMP_SUFFIX = '_timestamp_seconds';

    /**
     * Ticks: the real time of a real observation (null for a held tick), the real instant the tick anchors (the
     * observation time, or for the last tick of a hold the moment the hold ended), its phase and its samples.
     *
     * @var    list<array{real: ?float, anchor: ?float, phase: string, samples: array<string, array{name: string, value: float}>}>
     * @since  2.0.0
     */
    private array $ticks = [];

    /**
     * Checkpoints in the order they were declared.
     *
     * @var    list<array{name: string, at: int, fires: bool, phase: string}>
     * @since  2.0.0
     */
    private array $checkpoints = [];

    /**
     * Start an empty timeline.
     *
     * @param  list<string>           $metrics   Metric names the drill's alert reads; other scraped series are dropped.
     * @param  int                    $interval  Synthetic seconds between ticks; also the promtool series interval.
     * @param  array<string, string>  $scope     Label values the drill owns; a series carrying one of these labels
     *                                           with another value is dropped.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly array $metrics,
        private readonly int $interval = 60,
        private readonly array $scope = [],
    ) {
        if ($interval < 1) {
            throw RuleViolation::at('timeline', 'the tick interval must be positive');
        }
    }

    /**
     * Record one real observation as the next tick.
     *
     * @param   string                                                                   $phase     Drill phase label.
     * @param   float                                                                    $realTime  Wall-clock time of the observation.
     * @param   list<array{name: string, labels: array<string, string>, value: float}>  $samples   Samples with target labels attached.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function observe(string $phase, float $realTime, array $samples): void
    {
        $previous = $this->lastAnchor();
        if ($previous !== null && $realTime < $previous) {
            throw RuleViolation::at('timeline', 'observations must be recorded in real-time order');
        }
        $kept = [];
        foreach ($samples as $sample) {
            $foreign = array_diff_assoc(array_intersect_key($sample['labels'], $this->scope), $this->scope);
            if (in_array($sample['name'], $this->metrics, true) && $foreign === []) {
                $kept[Exposition::series($sample['name'], $sample['labels'])] = [
                    'name' => $sample['name'],
                    'value' => $sample['value'],
                ];
            }
        }
        $this->ticks[] = ['real' => $realTime, 'anchor' => $realTime, 'phase' => $phase, 'samples' => $kept];
    }

    /**
     * Let synthetic time pass with nothing new observed, repeating the last observation.
     *
     * The last held tick anchors the real instant the hold ended, so anything the drill does afterwards is
     * placed after the hold on the synthetic clock rather than squeezed in before it.
     *
     * @param   string  $phase     Drill phase label.
     * @param   int     $ticks     Number of intervals to hold for; at least one.
     * @param   float   $realTime  Wall-clock time the hold ends, normally now.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function hold(string $phase, int $ticks, float $realTime): void
    {
        $last = $this->ticks[array_key_last($this->ticks) ?? -1] ?? null;
        if ($last === null || $ticks < 1) {
            throw RuleViolation::at('timeline', 'a hold needs an observation to repeat and at least one tick');
        }
        $previous = $this->lastAnchor();
        if ($previous !== null && $realTime < $previous) {
            throw RuleViolation::at('timeline', 'observations must be recorded in real-time order');
        }
        for ($tick = 1; $tick <= $ticks; $tick++) {
            $this->ticks[] = [
                'real' => null,
                'anchor' => $tick === $ticks ? $realTime : null,
                'phase' => $phase,
                'samples' => $last['samples'],
            ];
        }
    }

    /**
     * Declare at the current tick whether the alert must be firing.
     *
     * @param   string  $name   Checkpoint name, for example `healthy`, `firing` or `cleared`.
     * @param   bool    $fires  Whether the drill's alert must be firing at this instant.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function expect(string $name, bool $fires): void
    {
        $last = array_key_last($this->ticks);
        if ($last === null) {
            throw RuleViolation::at('timeline', 'a checkpoint needs an observation');
        }
        $this->checkpoints[] = [
            'name' => $name,
            'at' => $last * $this->interval,
            'fires' => $fires,
            'phase' => $this->ticks[$last]['phase'],
        ];
    }

    /**
     * Report the latest real value of one series, for a drill's own precondition checks.
     *
     * @param   string                 $name    Metric name.
     * @param   array<string, string>  $labels  Exact label set, including target labels.
     *
     * @return  ?float  The value in the latest tick, or null when that tick did not contain the series.
     *
     * @since   2.0.0
     */
    public function latest(string $name, array $labels): ?float
    {
        $last = $this->ticks[array_key_last($this->ticks) ?? -1] ?? null;

        return $last['samples'][Exposition::series($name, $labels)]['value'] ?? null;
    }

    /**
     * Report the promtool series interval.
     *
     * @return  string  For example `1m` or `90s`.
     *
     * @since   2.0.0
     */
    public function interval(): string
    {
        return Duration::format($this->interval);
    }

    /**
     * Report the declared checkpoints.
     *
     * @return  list<array{name: string, at: int, fires: bool, phase: string}>  Checkpoints in declaration order.
     *
     * @since   2.0.0
     */
    public function checkpoints(): array
    {
        return $this->checkpoints;
    }

    /**
     * Summarise each phase's first and last tick on the synthetic clock and how many ticks were real.
     *
     * @return  array<string, array{from: int, to: int, observed: int, held: int}>  Seconds on the synthetic clock.
     *
     * @since   2.0.0
     */
    public function phases(): array
    {
        $phases = [];
        foreach ($this->ticks as $index => $tick) {
            $at = $index * $this->interval;
            $phase = $phases[$tick['phase']] ?? ['from' => $at, 'to' => $at, 'observed' => 0, 'held' => 0];
            $phase['to'] = $at;
            $phase[$tick['real'] === null ? 'held' : 'observed']++;
            $phases[$tick['phase']] = $phase;
        }

        return $phases;
    }

    /**
     * Render every series the drill kept in promtool's `input_series` notation.
     *
     * A tick without the series is `_` (no sample) unless the tick before had one, in which case it is `stale`,
     * matching the marker Prometheus writes when a series or its whole target disappears from a scrape.
     *
     * @return  list<array{series: string, values: string}>  Series in name order.
     *
     * @since   2.0.0
     */
    public function series(): array
    {
        $names = [];
        foreach ($this->ticks as $tick) {
            foreach ($tick['samples'] as $series => $sample) {
                $names[$series] = $sample['name'];
            }
        }
        ksort($names);
        $rendered = [];
        foreach ($names as $series => $name) {
            $values = [];
            $present = false;
            foreach ($this->ticks as $tick) {
                $sample = $tick['samples'][$series] ?? null;
                if ($sample === null) {
                    $values[] = $present ? 'stale' : '_';
                    $present = false;
                    continue;
                }
                $value = str_ends_with($name, self::TIMESTAMP_SUFFIX) ? $this->synthetic($sample['value']) : $sample['value'];
                $values[] = Exposition::value($value);
                $present = true;
            }
            $rendered[] = ['series' => $series, 'values' => implode(' ', $values)];
        }

        return $rendered;
    }

    /**
     * Map a wall-clock instant onto the synthetic clock.
     *
     * @param   float  $instant  Unix time.
     *
     * @return  float  Seconds on the synthetic clock.
     *
     * @since   2.0.0
     */
    public function synthetic(float $instant): float
    {
        $anchor = null;
        $next = null;
        foreach ($this->ticks as $index => $tick) {
            if ($tick['anchor'] === null) {
                continue;
            }
            if ($tick['anchor'] <= $instant) {
                $anchor = [$index * $this->interval, $tick['anchor']];
                $next = null;
                continue;
            }
            $next ??= $index * $this->interval;
        }
        if ($anchor === null) {
            foreach ($this->ticks as $index => $tick) {
                if ($tick['anchor'] !== null) {
                    return $index * $this->interval + ($instant - $tick['anchor']);
                }
            }

            return $instant;
        }
        $offset = $instant - $anchor[1];
        if ($next !== null) {
            $offset = min($offset, $next - $anchor[0] - 1);
        }

        return $anchor[0] + $offset;
    }

    /**
     * Report the latest anchored real instant.
     *
     * @return  ?float  Unix time, or null before the first observation.
     *
     * @since   2.0.0
     */
    private function lastAnchor(): ?float
    {
        for ($index = count($this->ticks) - 1; $index >= 0; $index--) {
            if ($this->ticks[$index]['anchor'] !== null) {
                return $this->ticks[$index]['anchor'];
            }
        }

        return null;
    }
}
