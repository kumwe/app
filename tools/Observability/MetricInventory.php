<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\MetricType;

/**
 * Every series name a rule or dashboard may query, with its type and the closed values of each label.
 *
 * The application's own families come from `MetricCatalog`, the same declaration the exposition endpoint
 * renders from, so a rule can only name a metric the process can actually emit and a label value the metric
 * can actually carry. Histograms expand into their `_bucket`, `_sum` and `_count` series. Prometheus adds the
 * target labels `instance` and `job` to every scraped series; together with `release` and `runtime` on
 * `kumwe_build_info` they are topology labels, bounded by the size of the deployment rather than by traffic.
 * The few series Kumwe does not emit itself — `up` and the synthetic probe's textfile output — are declared
 * here beside them.
 *
 * @since  2.0.0
 */
final readonly class MetricInventory
{
    /**
     * Labels every scraped series carries and that are bounded by deployment size.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const TOPOLOGY_LABELS = ['instance', 'job', 'release', 'runtime'];

    /**
     * Bind the inventory to its resolved series.
     *
     * @param  array<string, array{type: string, labels: array<string, list<string>>}>  $series  Series by name.
     *
     * @since  2.0.0
     */
    private function __construct(private array $series)
    {
    }

    /**
     * Build the inventory from the application catalogue and the probe's declared checks.
     *
     * @param   MetricCatalog  $catalog  Application metric catalogue.
     * @param   list<string>   $checks   Synthetic probe check names.
     *
     * @return  self  The inventory.
     *
     * @since   2.0.0
     */
    public static function create(MetricCatalog $catalog, array $checks): self
    {
        $series = ['up' => ['type' => 'gauge', 'labels' => []]];
        foreach ($catalog->definitions() as $definition) {
            $labels = $definition->labels;
            if ($definition->type === MetricType::Histogram) {
                $series[$definition->name . '_bucket'] = [
                    'type' => 'counter',
                    'labels' => $labels + ['le' => array_merge(
                        array_map(static fn (float $bound): string => (string) $bound, $definition->buckets),
                        ['+Inf'],
                    )],
                ];
                $series[$definition->name . '_sum'] = ['type' => 'counter', 'labels' => $labels];
                $series[$definition->name . '_count'] = ['type' => 'counter', 'labels' => $labels];
                continue;
            }
            $series[$definition->name] = [
                'type' => $definition->type === MetricType::Counter ? 'counter' : 'gauge',
                'labels' => $labels,
            ];
        }
        $series['kumwe_probe_success'] = ['type' => 'gauge', 'labels' => ['check' => $checks]];
        $series['kumwe_probe_duration_seconds'] = ['type' => 'gauge', 'labels' => ['check' => $checks]];
        $series['kumwe_probe_last_run_timestamp_seconds'] = ['type' => 'gauge', 'labels' => []];

        return new self($series);
    }

    /**
     * Look up one series name.
     *
     * @param   string  $name  Series name as a selector spells it.
     *
     * @return  ?array{type: string, labels: array<string, list<string>>}  The series, or null when undeclared.
     *
     * @since   2.0.0
     */
    public function series(string $name): ?array
    {
        return $this->series[$name] ?? null;
    }

    /**
     * List every declared series name.
     *
     * @return  list<string>  Names in declaration order.
     *
     * @since   2.0.0
     */
    public function names(): array
    {
        return array_keys($this->series);
    }

    /**
     * Report whether a label is bounded for a series: declared with an enumeration, or a topology label.
     *
     * @param   string  $name   Series name.
     * @param   string  $label  Label name.
     *
     * @return  bool  True when the label cannot mint series from traffic.
     *
     * @since   2.0.0
     */
    public function bounded(string $name, string $label): bool
    {
        return in_array($label, self::TOPOLOGY_LABELS, true)
            || array_key_exists($label, $this->series[$name]['labels'] ?? []);
    }

    /**
     * Count the values one label can take on a series, including the folded `other`.
     *
     * @param   string  $name   Series name.
     * @param   string  $label  Label name.
     *
     * @return  ?int  Number of values, or null for a topology label whose size the deployment decides.
     *
     * @since   2.0.0
     */
    public function cardinality(string $name, string $label): ?int
    {
        $values = $this->series[$name]['labels'][$label] ?? null;

        return $values === null ? null : count($values) + 1;
    }
}
