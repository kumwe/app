<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\App\Infrastructure\Observability\MetricRecorder;
use Kumwe\App\Infrastructure\Observability\MetricSample;

/**
 * Metric recorder double that keeps every increment and observation in memory for assertions.
 *
 * @since  2.0.0
 */
final class RecordingMetricRecorder implements MetricRecorder
{
    /**
     * Every increment, in order.
     *
     * @var    list<array{metric: string, labels: array<string, string>, value: float}>
     * @since  2.0.0
     */
    public array $increments = [];

    /**
     * Every observation, in order.
     *
     * @var    list<array{metric: string, labels: array<string, string>, value: float}>
     * @since  2.0.0
     */
    public array $observations = [];

    /**
     * Keep an increment.
     *
     * @param   string                 $metric  Family name.
     * @param   array<string, string>  $labels  Labels.
     * @param   float                  $value   Amount.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function increment(string $metric, array $labels = [], float $value = 1.0): void
    {
        $this->increments[] = ['metric' => $metric, 'labels' => $labels, 'value' => $value];
    }

    /**
     * Keep an observation.
     *
     * @param   string                 $metric       Family name.
     * @param   array<string, string>  $labels       Labels.
     * @param   float                  $observation  Value.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function observe(string $metric, array $labels, float $observation): void
    {
        $this->observations[] = ['metric' => $metric, 'labels' => $labels, 'value' => $observation];
    }

    /**
     * Report nothing; this double is for write-side assertions.
     *
     * @return  list<MetricSample>  Always empty.
     *
     * @since   2.0.0
     */
    public function samples(): array
    {
        return [];
    }

    /**
     * Sum the increments of one family, optionally restricted to one label set.
     *
     * @param   string                 $metric  Family name.
     * @param   array<string, string>  $labels  Labels that must match exactly, or empty for any.
     *
     * @return  float  Total.
     *
     * @since   2.0.0
     */
    public function total(string $metric, array $labels = []): float
    {
        $total = 0.0;
        foreach ($this->increments as $increment) {
            if ($increment['metric'] === $metric && ($labels === [] || $increment['labels'] === $labels)) {
                $total += $increment['value'];
            }
        }

        return $total;
    }
}
