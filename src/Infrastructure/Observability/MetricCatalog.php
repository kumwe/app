<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

use InvalidArgumentException;

/**
 * The complete, closed set of metrics this application is allowed to emit.
 *
 * Everything about the exposition endpoint is derived from here: what may be incremented, what may be
 * observed, which labels each family carries, and — because every label enumerates its values — how
 * many time series the whole endpoint can ever produce. That last property is the reason the catalog
 * exists as a first-class object rather than as scattered string literals. An exposition endpoint whose
 * label values come from request data is a denial-of-service vector against the monitoring system and a
 * privacy leak against the application: one `path` label carrying `/api/v1/business/records/x/{uuid}`
 * publishes every record identifier ever requested and mints a time series for each. The catalog refuses
 * that shape by construction, and refuses at build time any label name the observability contract's
 * `forbidden_labels` list names.
 *
 * The signals themselves are the ones `docs/operations/monitoring.md` already told operators to alert
 * on and which previously had no application-side source at all.
 *
 * @since  2.0.0
 */
final readonly class MetricCatalog
{
    /**
     * HTTP request counter, the source of the runbook's 5xx-rate signal.
     *
     * @var    string
     * @since  2.0.0
     */
    public const HTTP_REQUESTS = 'kumwe_http_requests_total';

    /**
     * HTTP request duration histogram, the source of the runbook's latency signal.
     *
     * @var    string
     * @since  2.0.0
     */
    public const HTTP_DURATION = 'kumwe_http_request_duration_seconds';

    /**
     * HTTP methods that get their own series; anything else folds into `other`.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * Response status classes that get their own series.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const STATUS_CLASSES = ['1xx', '2xx', '3xx', '4xx', '5xx'];

    /**
     * Duration histogram bounds, in seconds.
     *
     * The bounds straddle the range a page render actually occupies, with enough resolution below a
     * quarter second to see a latency regression before it becomes a timeout, and a tail out to ten
     * seconds so a saturated worker is visible rather than merely absent.
     *
     * @var    list<float>
     * @since  2.0.0
     */
    public const BUCKETS = [0.005, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    /**
     * Bind the catalogue to its definitions.
     *
     * @param  array<string, MetricDefinition>  $definitions  Declared families keyed by exposition name.
     *
     * @since  2.0.0
     */
    private function __construct(private array $definitions)
    {
    }

    /**
     * Build the catalogue, refusing any label the observability contract forbids.
     *
     * The refusal is a build-time assertion rather than a test-only rule, so a future contributor who
     * adds a `user_id` label to a metric gets an exception on the first boot instead of an unbounded
     * exposition endpoint in production.
     *
     * @param   ObservabilityContract  $contract  Declaration whose `forbidden_labels` list is enforced.
     * @param   string                 $release   Immutable release identifier stamped on `kumwe_build_info`.
     * @param   string                 $runtime   Surface this process serves, stamped on `kumwe_build_info`.
     *
     * @return  self  The catalogue every recorder and collector is bound to.
     *
     * @throws  InvalidArgumentException  When a declared metric carries a forbidden label name.
     *
     * @since   2.0.0
     */
    public static function create(ObservabilityContract $contract, string $release, string $runtime): self
    {
        $definitions = [];
        foreach (self::families($release, $runtime) as $definition) {
            foreach (array_keys($definition->labels) as $label) {
                if ($contract->forbidsLabel($label)) {
                    throw new InvalidArgumentException(
                        sprintf('Metric %s declares the forbidden label %s.', $definition->name, $label),
                    );
                }
            }
            $definitions[$definition->name] = $definition;
        }

        return new self($definitions);
    }

    /**
     * Read every declared family, in exposition order.
     *
     * @return  array<string, MetricDefinition>  Declared families keyed by exposition name.
     *
     * @since   2.0.0
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * Read one declared family by name.
     *
     * @param   string  $name  Exposition name of the family.
     *
     * @return  MetricDefinition  The declared family.
     *
     * @throws  InvalidArgumentException  When the name is not in the catalogue.
     *
     * @since   2.0.0
     */
    public function definition(string $name): MetricDefinition
    {
        return $this->definitions[$name]
            ?? throw new InvalidArgumentException(sprintf('Metric %s is not declared.', $name));
    }

    /**
     * Report the upper bound on the number of time series the whole endpoint can expose.
     *
     * A test asserts this stays small, which is what turns "we were careful about cardinality" into a
     * property the build re-checks on every change.
     *
     * @return  int<1, max>  Sum of every family's maximum series count.
     *
     * @since   2.0.0
     */
    public function maximumSeries(): int
    {
        $total = 0;
        foreach ($this->definitions as $definition) {
            $total += $definition->maximumSeries();
        }

        return max(1, $total);
    }

    /**
     * Fold an HTTP status code onto its exposition class.
     *
     * @param   int  $status  Response status code.
     *
     * @return  string  The status class, such as `5xx`, or `other` for a code outside the known ranges.
     *
     * @since   2.0.0
     */
    public static function statusClass(int $status): string
    {
        $class = intdiv($status, 100) . 'xx';

        return in_array($class, self::STATUS_CLASSES, true) ? $class : MetricDefinition::OTHER;
    }

    /**
     * Declare every metric family this application emits.
     *
     * @param   string  $release  Immutable release identifier stamped on `kumwe_build_info`.
     * @param   string  $runtime  Surface this process serves, stamped on `kumwe_build_info`.
     *
     * @return  list<MetricDefinition>  The declared families.
     *
     * @since   2.0.0
     */
    private static function families(string $release, string $runtime): array
    {
        return [
            new MetricDefinition(
                self::HTTP_REQUESTS,
                MetricType::Counter,
                'HTTP responses served, by request method and response status class.',
                ['method' => self::METHODS, 'status' => self::STATUS_CLASSES],
            ),
            new MetricDefinition(
                self::HTTP_DURATION,
                MetricType::Histogram,
                'HTTP request duration in seconds, by request method.',
                ['method' => self::METHODS],
                self::BUCKETS,
            ),
            ...self::gauges($release, $runtime),
        ];
    }

    /**
     * Declare the unlabelled gauges the scrape recomputes from the durable stores.
     *
     * They are unlabelled on purpose. Every dimension an operator might want to slice them by — the
     * queue, the consumer, the site — is a value an untrusted or merely growing input controls, so the
     * gauges answer "is anything stuck, and for how long" and the durable rows answer "which one".
     *
     * @param   string  $release  Immutable release identifier, the sole allowed `kumwe_build_info` release.
     * @param   string  $runtime  Surface this process serves, the sole allowed `kumwe_build_info` runtime.
     *
     * @return  list<MetricDefinition>  The declared gauge families.
     *
     * @since   2.0.0
     */
    private static function gauges(string $release, string $runtime): array
    {
        $declared = [
            'kumwe_build_info' => 'Always 1; carries the release and runtime surface of this process as labels.',
            'kumwe_ready' => 'Whether this process reports itself fit to take traffic: 1 ready, 0 not ready.',
            'kumwe_outbox_pending' => 'Integration outbox rows waiting to be dispatched.',
            'kumwe_outbox_oldest_pending_age_seconds' => 'Age of the oldest undispatched integration outbox row.',
            'kumwe_outbox_dead' => 'Integration outbox rows that exhausted their attempts.',
            'kumwe_inbox_pending' => 'Integration inbox rows waiting to be consumed.',
            'kumwe_inbox_oldest_pending_age_seconds' => 'Age of the oldest unconsumed integration inbox row.',
            'kumwe_inbox_poison' => 'Integration inbox rows quarantined as poison.',
            'kumwe_jobs_pending' => 'Queued jobs not yet completed.',
            'kumwe_jobs_due' => 'Queued jobs whose availability time has passed.',
            'kumwe_jobs_oldest_due_age_seconds' => 'Age of the oldest job whose availability time has passed.',
            'kumwe_jobs_lease_expired' => 'Claimed jobs whose worker lease has expired without settlement.',
            'kumwe_jobs_dead' => 'Jobs buried after exhausting their attempts.',
            'kumwe_jobs_dead_lettered' => 'Rows in the failed-job ledger.',
            'kumwe_workers_registered' => 'Worker heartbeat rows currently recorded.',
            'kumwe_worker_heartbeat_age_seconds' => 'Age of the freshest worker heartbeat; grows when no worker runs.',
            'kumwe_schedules_due' => 'Enabled schedules whose next run time has passed.',
            'kumwe_scheduler_lag_seconds' => 'How far the most overdue enabled schedule is past its next run time.',
            'kumwe_process_work_overdue' => 'Long-running process work items past their due time.',
            'kumwe_process_work_oldest_overdue_age_seconds' => 'Age of the oldest overdue process work item.',
            'kumwe_export_queue_depth' => 'Report export artifacts queued or running.',
            'kumwe_export_artifacts_expired' => 'Report export artifacts past their expiry that are still stored.',
            'kumwe_metrics_scrape_duration_seconds' => 'Wall time the last scrape spent collecting these metrics.',
            'kumwe_metrics_collection_failed' => 'Whether the last collection raised: 1 failed, 0 succeeded.',
        ];
        $gauges = [];
        foreach ($declared as $name => $help) {
            $gauges[] = new MetricDefinition(
                $name,
                MetricType::Gauge,
                $help,
                $name === 'kumwe_build_info' ? ['release' => [$release], 'runtime' => [$runtime]] : [],
            );
        }

        return $gauges;
    }
}
