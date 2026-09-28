<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Readiness\ReadinessStatus;
use Kumwe\App\Application\Retention\RetentionObserver;
use Kumwe\App\Application\Retention\RetentionReadiness;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Recomputes the queue, outbox, scheduler and export gauges from the durable rows at scrape time.
 *
 * `docs/operations/monitoring.md` has always told operators to alert on queue depth and oldest age,
 * outbox pending age, scheduler lag and export queue age. None of those had an application-side source,
 * so an operator either hand-wrote the SQL against production or did not have the signal. This class is
 * that source, and it is deliberately a scrape-time collector rather than a background writer: the
 * numbers are already in the database, and copying them into a second store on a schedule would only
 * add a way for the copy to be wrong.
 *
 * Every query is a bounded probe over an index the claim path already maintains (V2-SCL-005): a
 * depth is counted over at most `PROBE_CAP` index entries and an age is the first entry of an ordered
 * index range, so the whole collection is a fixed number of statements whose cost does not grow with
 * table size. A capped depth is a lower bound and `kumwe_metrics_capped_gauges` counts them; the exact
 * figures are an operator diagnostic (`LedgerCensus`) with an explicit cost class and timeout. Ages are
 * computed in PHP rather than in SQL, which keeps one query text working identically on every engine.
 *
 * Collection never raises. A monitoring endpoint that returns 500 because a table is momentarily locked
 * has converted a missing graph into a paging incident, so a failure is reported as the
 * `kumwe_metrics_collection_failed` gauge and the remaining samples are served.
 *
 * @since  2.0.0
 */
final readonly class RuntimeMetricCollector implements MetricCollector
{
    /**
     * Forecast published when the drain slope predicts no exhaustion: ten years, so thresholds never fire.
     *
     * @var    float
     * @since  2.0.0
     */
    public const float NO_EXHAUSTION_PREDICTED_SECONDS = 315_360_000.0;

    /**
     * Most index entries a depth probe examines before it reports a lower bound instead of an exact depth.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int PROBE_CAP = 10_000;

    /**
     * Bind the collector to the stores it aggregates.
     *
     * @param  Connection           $database            Connection the bounded aggregates run on.
     * @param  TableNames           $tables              Resolver for the prefixed physical table names.
     * @param  ClockInterface       $clock               Reading every age is computed against.
     * @param  ReadinessStatus      $readiness           Cheap readiness verdict published as `kumwe_ready`.
     * @param  string               $release             Immutable release identifier stamped on `kumwe_build_info`.
     * @param  string               $runtime             Surface this process serves, stamped on `kumwe_build_info`.
     * @param  ?RetentionObserver   $retention           Source of the six retention gauges per store, or
     *         null to leave retention out of the scrape.
     * @param  ?RetentionReadiness  $retentionReadiness  Assessment behind `kumwe_retention_readiness`.
     * @param  bool                 $enterprise          Whether the installation declares the enterprise
     *         capacity profile, which turns a missing retention setting into a failed verdict.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
        private ReadinessStatus $readiness,
        private string $release,
        private string $runtime,
        private ?RetentionObserver $retention = null,
        private ?RetentionReadiness $retentionReadiness = null,
        private bool $enterprise = false,
    ) {
    }

    /**
     * Collect every gauge the exposition endpoint publishes.
     *
     * @return  list<MetricSample>  The gauge samples, including the scrape's own duration and whether
     *          collection raised.
     *
     * @since   2.0.0
     */
    public function collect(): array
    {
        $started = microtime(true);
        $now = $this->clock->now();
        $samples = [
            new MetricSample(
                'kumwe_build_info',
                'kumwe_build_info',
                ['release' => $this->release, 'runtime' => $this->runtime],
                1.0,
            ),
            $this->gauge('kumwe_ready', $this->ready() ? 1.0 : 0.0),
        ];
        $failed = false;
        try {
            $samples = array_merge($samples, $this->durable($now));
        } catch (Throwable) {
            $failed = true;
        }
        try {
            $samples = array_merge($samples, $this->retention());
        } catch (Throwable) {
            $failed = true;
        }
        $samples[] = $this->gauge('kumwe_metrics_collection_failed', $failed ? 1.0 : 0.0);
        $samples[] = $this->gauge('kumwe_metrics_scrape_duration_seconds', microtime(true) - $started);

        return $samples;
    }

    /**
     * Read every gauge that comes from a durable row, each through a bounded indexed probe.
     *
     * No statement here is an exact aggregate over a hot table. A depth is a count over a derived table
     * capped at `PROBE_CAP` rows, so the work is bounded by the cap rather than by the table, and
     * `kumwe_metrics_capped_gauges` says how many depths reached the cap and are therefore lower bounds.
     * An age is the first entry of an ascending index range. The exact figures remain available to an
     * operator through `LedgerCensus`, which carries an explicit cost class and statement timeout.
     *
     * @param   DateTimeImmutable  $now  Reading ages are measured against.
     *
     * @return  list<MetricSample>  Gauge samples read from the database.
     *
     * @since   2.0.0
     */
    private function durable(DateTimeImmutable $now): array
    {
        $outbox = $this->tables->quoted('integration_outbox');
        $inbox = $this->tables->quoted('integration_inbox');
        $jobs = $this->tables->quoted('jobs');
        $failed = $this->tables->quoted('failed_jobs');
        $heartbeats = $this->tables->quoted('worker_heartbeats');
        $schedules = $this->tables->quoted('schedules');
        $work = $this->tables->quoted('business_process_work');
        $exports = $this->tables->quoted('business_report_export_artifacts');
        $staging = $this->tables->quoted('business_projection_event_staging');
        $depths = [
            'kumwe_outbox_pending' => [$outbox, "status IN ('pending', 'reserved')", null],
            'kumwe_outbox_dead' => [$outbox, "status = 'dead'", null],
            'kumwe_inbox_pending' => [$inbox, "status IN ('pending', 'reserved')", null],
            'kumwe_inbox_poison' => [$inbox, "status = 'poison'", null],
            'kumwe_jobs_pending' => [$jobs, "status IN ('pending', 'reserved')", null],
            'kumwe_jobs_due' => [$jobs, "status = 'pending'", 'available_at'],
            'kumwe_jobs_lease_expired' => [$jobs, "status = 'reserved'", 'lease_expires_at'],
            'kumwe_jobs_dead' => [$jobs, "status = 'dead'", null],
            'kumwe_jobs_dead_lettered' => [$failed, '1 = 1', null],
            'kumwe_workers_registered' => [$heartbeats, '1 = 1', null],
            'kumwe_schedules_due' => [$schedules, $this->enabled(), 'next_run_at'],
            'kumwe_process_work_overdue' => [$work, "status IN ('pending', 'reserved')", 'due_at'],
            'kumwe_export_queue_depth' => [$exports, "status IN ('queued', 'running')", null],
            'kumwe_export_artifacts_expired' => [$exports, '1 = 1', 'expires_at'],
            'kumwe_projection_staging_backlog' => [$staging, '1 = 1', null],
        ];
        $samples = [];
        $capped = 0;
        foreach ($depths as $name => [$table, $predicate, $column]) {
            [$value, $hit] = $this->bounded($table, $predicate, $column, $now);
            $capped += $hit ? 1 : 0;
            $samples[] = $this->gauge($name, $value);
        }
        $ages = [
            'kumwe_outbox_oldest_pending_age_seconds' => [$outbox, 'created_at', "status IN ('pending', 'reserved')"],
            'kumwe_inbox_oldest_pending_age_seconds' => [
                $inbox,
                'first_received_at',
                "status IN ('pending', 'reserved')",
            ],
            'kumwe_jobs_oldest_due_age_seconds' => [$jobs, 'available_at', "status = 'pending'"],
            'kumwe_scheduler_lag_seconds' => [$schedules, 'next_run_at', $this->enabled()],
            'kumwe_process_work_oldest_overdue_age_seconds' => [$work, 'due_at', "status IN ('pending', 'reserved')"],
            'kumwe_projection_staging_oldest_age_seconds' => [$staging, 'recorded_at', '1 = 1'],
        ];
        foreach ($ages as $name => [$table, $column, $predicate]) {
            $samples[] = $this->gauge($name, $this->age($table, $column, $predicate, $now));
        }
        $samples[] = $this->gauge(
            'kumwe_worker_heartbeat_age_seconds',
            $this->youngest($heartbeats, 'heartbeat_at', $now),
        );
        foreach ($this->server() as $name => $value) {
            $samples[] = $this->gauge($name, $value);
        }
        $samples[] = $this->gauge('kumwe_metrics_capped_gauges', (float) $capped);

        return $samples;
    }

    /**
     * Read connection saturation and replica lag from the server's own catalogue, never from app tables.
     *
     * Each figure is a single constant-cost statement. A figure the account may not read is published as
     * zero rather than failing the scrape, and the runbook says so.
     *
     * @return  array<string, float>  Connection and replica gauges.
     *
     * @since   2.0.0
     */
    private function server(): array
    {
        $postgres = $this->database->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $read = function (string $sql): float {
            try {
                return $this->tally($this->database->fetchOne($sql));
            } catch (Throwable) {
                return 0.0;
            }
        };
        if ($postgres) {
            return [
                'kumwe_database_connections_in_use' => $read('SELECT COUNT(*) FROM pg_stat_activity'),
                'kumwe_database_connections_max' => $read(
                    "SELECT setting::int FROM pg_settings WHERE name = 'max_connections'",
                ),
                'kumwe_database_replica_lag_seconds' => $read(
                    'SELECT COALESCE(MAX(EXTRACT(EPOCH FROM replay_lag)), 0) FROM pg_stat_replication',
                ),
            ];
        }
        $status = function (string $name): float {
            try {
                $row = $this->database->fetchAssociative(sprintf("SHOW GLOBAL STATUS LIKE '%s'", $name));
            } catch (Throwable) {
                return 0.0;
            }

            return $row === false ? 0.0 : $this->tally(array_values($row)[1] ?? null);
        };

        return [
            'kumwe_database_connections_in_use' => $status('Threads_connected'),
            'kumwe_database_connections_max' => $read('SELECT @@max_connections'),
            'kumwe_database_replica_lag_seconds' => $this->replicaLag(),
        ];
    }

    /**
     * Read the MySQL-family replica lag this server reports about itself, when it is a replica.
     *
     * @return  float  Seconds behind the source, or zero on a primary or when the account may not look.
     *
     * @since   2.0.0
     */
    private function replicaLag(): float
    {
        try {
            $row = $this->database->fetchAssociative('SHOW REPLICA STATUS');
        } catch (Throwable) {
            try {
                $row = $this->database->fetchAssociative('SHOW SLAVE STATUS');
            } catch (Throwable) {
                return 0.0;
            }
        }
        if ($row === false) {
            return 0.0;
        }

        return $this->tally($row['Seconds_Behind_Master'] ?? $row['Seconds_Behind_Source'] ?? null);
    }

    /**
     * Count rows matching a predicate, optionally whose timestamp has passed, up to the probe cap.
     *
     * @param   string             $table      Quoted physical table name.
     * @param   string             $predicate  SQL predicate built only from literals in this class.
     * @param   ?string            $column     Timestamp column that must be at or before now, or null.
     * @param   DateTimeImmutable  $now        Reading the column is compared against.
     *
     * @return  array{float, bool}  Count and whether it reached the cap.
     *
     * @since   2.0.0
     */
    private function bounded(string $table, string $predicate, ?string $column, DateTimeImmutable $now): array
    {
        $sql = sprintf(
            'SELECT COUNT(*) FROM (SELECT 1 AS probe FROM %s WHERE %s%s LIMIT %d) bounded',
            $table,
            $predicate,
            $column === null ? '' : sprintf(' AND %s <= ?', $column),
            self::PROBE_CAP,
        );
        $value = $this->tally($column === null
            ? $this->database->fetchOne($sql)
            : $this->database->fetchOne($sql, [$now], [Types::DATETIME_IMMUTABLE]));

        return [$value, $value >= self::PROBE_CAP];
    }

    /**
     * Normalise a driver-returned count into a gauge value.
     *
     * Drivers disagree about whether an aggregate comes back as an integer or as a decimal string, and
     * on 32-bit builds a large `COUNT` arrives as a string on every engine. Reading both shapes here
     * keeps that difference out of every call site.
     *
     * @param   mixed  $value  Value the driver returned for the aggregate.
     *
     * @return  float  Non-negative count; zero for anything the driver did not return as a number.
     *
     * @since   2.0.0
     */
    private function tally(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return max(0.0, (float) $value);
        }

        return is_string($value) && is_numeric($value) ? max(0.0, (float) $value) : 0.0;
    }

    /**
     * Measure how far in the past the oldest matching row's timestamp lies, from an ascending index range.
     *
     * @param   string             $table      Quoted physical table name.
     * @param   string             $column     Timestamp column the range is ordered by.
     * @param   string             $predicate  SQL predicate built only from literals in this class.
     * @param   DateTimeImmutable  $now        Reading the age is measured against.
     *
     * @return  float  Age in seconds, or zero when nothing matches or the oldest row is still in the future.
     *
     * @since   2.0.0
     */
    private function age(string $table, string $column, string $predicate, DateTimeImmutable $now): float
    {
        $oldest = $this->database->fetchOne(sprintf(
            'SELECT %1$s FROM %2$s WHERE %3$s AND %1$s IS NOT NULL ORDER BY %1$s LIMIT 1',
            $column,
            $table,
            $predicate,
        ));

        return $this->elapsed($oldest, $now);
    }

    /**
     * Measure how far in the past the newest row's timestamp lies, from a descending index range.
     *
     * @param   string             $table   Quoted physical table name.
     * @param   string             $column  Timestamp column the range is ordered by.
     * @param   DateTimeImmutable  $now     Reading the age is measured against.
     *
     * @return  float  Age in seconds, or zero when the table is empty.
     *
     * @since   2.0.0
     */
    private function youngest(string $table, string $column, DateTimeImmutable $now): float
    {
        $newest = $this->database->fetchOne(sprintf(
            'SELECT %1$s FROM %2$s WHERE %1$s IS NOT NULL ORDER BY %1$s DESC LIMIT 1',
            $column,
            $table,
        ));

        return $this->elapsed($newest, $now);
    }

    /**
     * Publish the six retention gauges per store and the readiness verdict, when an observer is wired.
     *
     * A forecast of no predicted exhaustion is published as the maximum representable horizon rather
     * than omitted, so a dashboard threshold on the gauge fires only on a real prediction. An unknown
     * rate is published as zero; the observation's own flags say why, and the runbook documents which
     * stores record no arrival instant.
     *
     * @return  list<MetricSample>  Retention samples, or an empty list when no observer is wired.
     *
     * @since   2.0.0
     */
    private function retention(): array
    {
        if ($this->retention === null) {
            return [];
        }
        $samples = [];
        $observations = $this->retention->observeAll();
        foreach ($observations as $observation) {
            $labels = ['store' => $observation->store->value];
            $values = [
                $observation->ingestRowsPerSecond ?? 0.0,
                $observation->expiryRowsPerSecond ?? 0.0,
                $observation->drainRowsPerSecond ?? 0.0,
                (float) $observation->backlogRows,
                $observation->oldestEligibleAgeSeconds ?? 0.0,
                $observation->forecastSecondsToCapacity ?? self::NO_EXHAUSTION_PREDICTED_SECONDS,
            ];
            foreach (MetricCatalog::RETENTION_GAUGES as $index => $name) {
                $samples[] = new MetricSample($name, $name, $labels, $values[$index]);
            }
        }
        if ($this->retentionReadiness !== null) {
            $verdict = $this->retentionReadiness->assess($observations, $this->enterprise);
            $samples[] = $this->gauge(MetricCatalog::RETENTION_READINESS, (float) $verdict->state->value);
        }

        return $samples;
    }

    /**
     * Spell the enabled-schedule predicate the way every supported engine accepts.
     *
     * PostgreSQL will not compare a native boolean with `1`, and MySQL-family engines have no boolean
     * type to compare against `true`, so the one portable spelling is the column on its own where the
     * platform has real booleans and a numeric comparison where it does not.
     *
     * @return  string  Predicate selecting enabled schedules.
     *
     * @since   2.0.0
     */
    private function enabled(): string
    {
        return $this->database->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? 'enabled = true'
            : 'enabled = 1';
    }

    /**
     * Turn a driver-returned timestamp into an age in seconds.
     *
     * @param   mixed              $value  Value the driver returned for the aggregate.
     * @param   DateTimeImmutable  $now    Reading the age is measured against.
     *
     * @return  float  Non-negative age in seconds; zero for a null, unparseable or future timestamp.
     *
     * @since   2.0.0
     */
    private function elapsed(mixed $value, DateTimeImmutable $now): float
    {
        if ($value instanceof DateTimeImmutable) {
            return max(0.0, (float) ($now->getTimestamp() - $value->getTimestamp()));
        }
        if (!is_string($value) || $value === '') {
            return 0.0;
        }
        $parsed = date_create_immutable($value);

        return $parsed === false ? 0.0 : max(0.0, (float) ($now->getTimestamp() - $parsed->getTimestamp()));
    }

    /**
     * Read the readiness verdict without letting a probe failure fail the scrape.
     *
     * @return  bool  Whether this process reports itself fit to take traffic.
     *
     * @since   2.0.0
     */
    private function ready(): bool
    {
        try {
            return $this->readiness->ready();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Build one unlabelled gauge sample.
     *
     * @param   string  $name   Declared gauge family name.
     * @param   float   $value  Current value.
     *
     * @return  MetricSample  The sample.
     *
     * @since   2.0.0
     */
    private function gauge(string $name, float $value): MetricSample
    {
        return new MetricSample($name, $name, [], $value);
    }
}
