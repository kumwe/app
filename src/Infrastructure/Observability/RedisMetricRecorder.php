<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

use Kumwe\App\Infrastructure\Redis\RedisRuntime;
use Throwable;

/**
 * Keeps counters and histograms in Redis so they survive the end of the request that incremented them.
 *
 * A counter has to outlive a request, and in a share-nothing runtime the only things that do are the
 * database and Redis. The database is out: writing a row per HTTP response would make observing the
 * system the most expensive thing the system does. Redis is already a required dependency, already
 * holds exactly this class of non-authoritative coordination state, and answers a pipelined hash
 * increment in well under a millisecond on a local socket.
 *
 * The keyspace is bounded by construction, not by convention. Every field name is assembled from a
 * `MetricCatalog` definition and label values the definition already folded onto its enumeration, so
 * the hash cannot grow past the catalogue's maximum series count however the caller behaves.
 *
 * Failure is always swallowed. Redis being down is a monitoring outage, and a monitoring outage must
 * never become an application outage — the readiness probe already reports Redis separately, so the
 * condition is visible without this class raising it into a page render.
 *
 * @since  2.0.0
 */
final readonly class RedisMetricRecorder implements MetricRecorder
{
    /**
     * Separator between the metric name and each bound label inside a stored field name.
     *
     * The unit separator cannot occur in a metric name or in an enumerated label value, so parsing a
     * stored field back into a series is unambiguous without escaping.
     *
     * @var    string
     * @since  2.0.0
     */
    private const SEPARATOR = "\x1f";

    /**
     * Suffix of the field holding a histogram's running total.
     *
     * @var    string
     * @since  2.0.0
     */
    private const SUM = '_sum';

    /**
     * Suffix of the field holding a histogram's observation count.
     *
     * @var    string
     * @since  2.0.0
     */
    private const COUNT = '_count';

    /**
     * Bind the recorder to its store and to the catalogue that bounds it.
     *
     * @param  RedisRuntime   $redis    Store the counters and histograms live in.
     * @param  MetricCatalog  $catalog  Declared families; anything not declared is silently dropped.
     *
     * @since  2.0.0
     */
    public function __construct(private RedisRuntime $redis, private MetricCatalog $catalog)
    {
    }

    /**
     * Add to a declared counter.
     *
     * @param   string                 $metric  Declared counter family name.
     * @param   array<string, string>  $labels  Labels to bind against the declared enumeration.
     * @param   float                  $value   Amount to add.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function increment(string $metric, array $labels = [], float $value = 1.0): void
    {
        try {
            $definition = $this->catalog->definition($metric);
            if ($definition->type !== MetricType::Counter) {
                return;
            }
            $this->redis->incrementMetrics([$this->field($metric, $definition->bind($labels)) => $value]);
        } catch (Throwable) {
            // Metric emission never changes the behaviour of the path it observes.
        }
    }

    /**
     * Record one observation into a declared histogram.
     *
     * Only the bucket the observation falls in is incremented; the exposition renderer accumulates them
     * into the cumulative counts the format requires. That keeps the write cost at three fields per
     * observation instead of one per bucket, which matters because this runs on every response.
     *
     * @param   string                 $metric       Declared histogram family name.
     * @param   array<string, string>  $labels       Labels to bind against the declared enumeration.
     * @param   float                  $observation  Observed value, in seconds for the shipped families.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function observe(string $metric, array $labels, float $observation): void
    {
        try {
            $definition = $this->catalog->definition($metric);
            if ($definition->type !== MetricType::Histogram) {
                return;
            }
            $bound = $definition->bind($labels);
            $bucket = '+Inf';
            foreach ($definition->buckets as $upper) {
                if ($observation <= $upper) {
                    $bucket = self::number($upper);
                    break;
                }
            }
            $this->redis->incrementMetrics([
                $this->field($metric, $bound + ['le' => $bucket]) => 1.0,
                $this->field($metric . self::SUM, $bound) => $observation,
                $this->field($metric . self::COUNT, $bound) => 1.0,
            ]);
        } catch (Throwable) {
            // Metric emission never changes the behaviour of the path it observes.
        }
    }

    /**
     * Read every stored series back into exposition samples.
     *
     * @return  list<MetricSample>  Counter series, and the cumulative bucket, sum and count series of
     *          every histogram that has an observation; empty when the store is unreachable.
     *
     * @since   2.0.0
     */
    public function samples(): array
    {
        try {
            $stored = $this->redis->metrics();
        } catch (Throwable) {
            return [];
        }
        $samples = [];
        $histograms = [];
        foreach ($stored as $field => $value) {
            $parsed = $this->parse($field);
            if ($parsed === null) {
                continue;
            }
            [$name, $labels] = $parsed;
            $definition = $this->catalog->definitions()[$name] ?? null;
            if ($definition !== null && $definition->type === MetricType::Counter) {
                $samples[] = new MetricSample($name, $name, $labels, $value);
                continue;
            }
            $family = $this->histogramFamily($name);
            if ($family !== null) {
                $histograms[$family][$field] = [$name, $labels, $value];
            }
        }
        foreach ($histograms as $family => $fields) {
            $samples = array_merge($samples, $this->histogram($family, $fields));
        }

        return $samples;
    }

    /**
     * Assemble the stored field name for one series.
     *
     * @param   string                 $name    Series name, including any histogram suffix.
     * @param   array<string, string>  $labels  Bound labels.
     *
     * @return  string  Field name used inside the metrics hash.
     *
     * @since   2.0.0
     */
    private function field(string $name, array $labels): string
    {
        $field = $name;
        foreach ($labels as $label => $value) {
            $field .= self::SEPARATOR . $label . '=' . $value;
        }

        return $field;
    }

    /**
     * Split a stored field name back into its series name and labels.
     *
     * @param   string  $field  Stored field name.
     *
     * @return  ?array{0: string, 1: array<string, string>}  Series name and labels, or null when the field
     *          predates the current catalogue and cannot be interpreted.
     *
     * @since   2.0.0
     */
    private function parse(string $field): ?array
    {
        $parts = explode(self::SEPARATOR, $field);
        $name = array_shift($parts);
        if ($name === null || $name === '') {
            return null;
        }
        $labels = [];
        foreach ($parts as $part) {
            $split = explode('=', $part, 2);
            if (count($split) !== 2 || $split[0] === '') {
                return null;
            }
            $labels[$split[0]] = $split[1];
        }

        return [$name, $labels];
    }

    /**
     * Name the declared histogram family a stored series belongs to.
     *
     * @param   string  $name  Stored series name.
     *
     * @return  ?string  Declared family name, or null when the series is not part of one.
     *
     * @since   2.0.0
     */
    private function histogramFamily(string $name): ?string
    {
        foreach ([self::SUM, self::COUNT] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                $name = substr($name, 0, -strlen($suffix));
                break;
            }
        }
        $definition = $this->catalog->definitions()[$name] ?? null;

        return $definition !== null && $definition->type === MetricType::Histogram ? $name : null;
    }

    /**
     * Turn one histogram family's stored fields into cumulative exposition samples.
     *
     * @param string $family Declared histogram family name.
     * @param   array<string, array{0: string, 1: array<string, string>, 2: float}>  $fields  Stored series.
     *
     * @return  list<MetricSample>  Cumulative `_bucket` series plus `_sum` and `_count`.
     *
     * @since   2.0.0
     */
    private function histogram(string $family, array $fields): array
    {
        $definition = $this->catalog->definition($family);
        /** @var array<string, array<string, string>> $series */
        $series = [];
        /** @var array<string, array<string, float>> $counts */
        $counts = [];
        /** @var array<string, float> $sums */
        $sums = [];
        /** @var array<string, float> $observations */
        $observations = [];
        foreach ($fields as [$name, $labels, $value]) {
            $le = $labels['le'] ?? null;
            unset($labels['le']);
            $key = self::key($labels);
            $series[$key] = $labels;
            if ($name === $family . self::SUM) {
                $sums[$key] = $value;
            } elseif ($name === $family . self::COUNT) {
                $observations[$key] = $value;
            } elseif (is_string($le)) {
                $counts[$key][$le] = $value;
            }
        }
        $samples = [];
        $bounds = [...array_map(self::number(...), $definition->buckets), '+Inf'];
        foreach ($series as $key => $labels) {
            $running = 0.0;
            foreach ($bounds as $bound) {
                $running += $counts[$key][$bound] ?? 0.0;
                $samples[] = new MetricSample($family, $family . '_bucket', $labels + ['le' => $bound], $running);
            }
            $samples[] = new MetricSample($family, $family . self::SUM, $labels, $sums[$key] ?? 0.0);
            $samples[] = new MetricSample($family, $family . self::COUNT, $labels, $observations[$key] ?? 0.0);
        }

        return $samples;
    }

    /**
     * Build a stable grouping key for one label set.
     *
     * @param   array<string, string>  $labels  Bound labels.
     *
     * @return  string  Deterministic key for grouping series that differ only by bucket.
     *
     * @since   2.0.0
     */
    private static function key(array $labels): string
    {
        ksort($labels);

        return implode(',', array_map(
            static fn (string $label, string $value): string => $label . '=' . $value,
            array_keys($labels),
            array_values($labels),
        ));
    }

    /**
     * Render a bucket bound the way the exposition format writes it.
     *
     * @param   float  $bound  Upper bound of the bucket.
     *
     * @return  string  Locale-independent decimal rendering.
     *
     * @since   2.0.0
     */
    private static function number(float $bound): string
    {
        $rendered = rtrim(rtrim(number_format($bound, 6, '.', ''), '0'), '.');

        return $rendered === '' ? '0' : $rendered;
    }
}
