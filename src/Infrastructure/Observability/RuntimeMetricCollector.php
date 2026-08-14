<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Observability;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\ReadinessStatus;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
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
 * Every query is a bounded aggregate over an index the claim path already maintains — a `COUNT` or a
 * `MIN` over `(status, available_at)` or `(status, due_at)` — so the whole collection is a fixed number
 * of cheap statements regardless of table size. Ages are computed in PHP from a `MIN(timestamp)` rather
 * than in SQL, which keeps one query text working identically on MariaDB, MySQL and PostgreSQL instead
 * of three engine-specific date expressions.
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
     * Bind the collector to the stores it aggregates.
     *
     * @param  Connection       $database   Connection the bounded aggregates run on.
     * @param  TableNames       $tables     Resolver for the prefixed physical table names.
     * @param  ClockInterface   $clock      Reading every age is computed against.
     * @param  ReadinessStatus  $readiness  Cheap readiness verdict published as `kumwe_ready`.
     * @param  string           $release    Immutable release identifier stamped on `kumwe_build_info`.
     * @param  string           $runtime    Surface this process serves, stamped on `kumwe_build_info`.
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
        $samples[] = $this->gauge('kumwe_metrics_collection_failed', $failed ? 1.0 : 0.0);
        $samples[] = $this->gauge('kumwe_metrics_scrape_duration_seconds', microtime(true) - $started);

        return $samples;
    }

    /**
     * Read every gauge that comes from a durable row.
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

        return [
            $this->gauge('kumwe_outbox_pending', $this->count($outbox, "status IN ('pending', 'reserved')")),
            $this->gauge('kumwe_outbox_oldest_pending_age_seconds', $this->age(
                $outbox,
                'created_at',
                "status IN ('pending', 'reserved')",
                $now,
            )),
            $this->gauge('kumwe_outbox_dead', $this->count($outbox, "status = 'dead'")),
            $this->gauge('kumwe_inbox_pending', $this->count($inbox, "status IN ('pending', 'reserved')")),
            $this->gauge('kumwe_inbox_oldest_pending_age_seconds', $this->age(
                $inbox,
                'first_received_at',
                "status IN ('pending', 'reserved')",
                $now,
            )),
            $this->gauge('kumwe_inbox_poison', $this->count($inbox, "status = 'poison'")),
            $this->gauge('kumwe_jobs_pending', $this->count($jobs, "status IN ('pending', 'reserved')")),
            $this->gauge('kumwe_jobs_due', $this->countBefore($jobs, "status = 'pending'", 'available_at', $now)),
            $this->gauge('kumwe_jobs_oldest_due_age_seconds', $this->age(
                $jobs,
                'available_at',
                "status = 'pending'",
                $now,
            )),
            $this->gauge('kumwe_jobs_lease_expired', $this->countBefore(
                $jobs,
                "status = 'reserved'",
                'lease_expires_at',
                $now,
            )),
            $this->gauge('kumwe_jobs_dead', $this->count($jobs, "status = 'dead'")),
            $this->gauge('kumwe_jobs_dead_lettered', $this->count($failed, '1 = 1')),
            $this->gauge('kumwe_workers_registered', $this->count($heartbeats, '1 = 1')),
            $this->gauge(
                'kumwe_worker_heartbeat_age_seconds',
                $this->youngest($heartbeats, 'heartbeat_at', $now),
            ),
            $this->gauge('kumwe_schedules_due', $this->countBefore(
                $schedules,
                $this->enabled(),
                'next_run_at',
                $now,
            )),
            $this->gauge('kumwe_scheduler_lag_seconds', $this->age($schedules, 'next_run_at', $this->enabled(), $now)),
            $this->gauge('kumwe_process_work_overdue', $this->countBefore(
                $work,
                "status IN ('pending', 'reserved')",
                'due_at',
                $now,
            )),
            $this->gauge('kumwe_process_work_oldest_overdue_age_seconds', $this->age(
                $work,
                'due_at',
                "status IN ('pending', 'reserved')",
                $now,
            )),
            $this->gauge('kumwe_export_queue_depth', $this->count($exports, "status IN ('queued', 'running')")),
            $this->gauge('kumwe_export_artifacts_expired', $this->countBefore($exports, '1 = 1', 'expires_at', $now)),
        ];
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
     * Count rows matching a predicate.
     *
     * @param   string  $table      Quoted physical table name.
     * @param   string  $predicate  SQL predicate built only from literals in this class.
     *
     * @return  float  Row count.
     *
     * @since   2.0.0
     */
    private function count(string $table, string $predicate): float
    {
        return $this->tally($this->database->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s', $table, $predicate),
        ));
    }

    /**
     * Count rows matching a predicate whose timestamp column has already passed.
     *
     * @param   string             $table      Quoted physical table name.
     * @param   string             $predicate  SQL predicate built only from literals in this class.
     * @param   string             $column     Timestamp column compared against the reading.
     * @param   DateTimeImmutable  $now        Reading the column is compared against.
     *
     * @return  float  Row count.
     *
     * @since   2.0.0
     */
    private function countBefore(string $table, string $predicate, string $column, DateTimeImmutable $now): float
    {
        return $this->tally($this->database->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s AND %s <= ?', $table, $predicate, $column),
            [$now],
            [Types::DATETIME_IMMUTABLE],
        ));
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
     * Measure how far in the past the oldest matching row's timestamp lies.
     *
     * @param   string             $table      Quoted physical table name.
     * @param   string             $column     Timestamp column the minimum is taken over.
     * @param   string             $predicate  SQL predicate built only from literals in this class.
     * @param   DateTimeImmutable  $now        Reading the age is measured against.
     *
     * @return  float  Age in seconds, or zero when nothing matches or the oldest row is still in the future.
     *
     * @since   2.0.0
     */
    private function age(string $table, string $column, string $predicate, DateTimeImmutable $now): float
    {
        $oldest = $this->database->fetchOne(
            sprintf('SELECT MIN(%s) FROM %s WHERE %s', $column, $table, $predicate),
        );

        return $this->elapsed($oldest, $now);
    }

    /**
     * Measure how far in the past the newest row's timestamp lies.
     *
     * @param   string             $table   Quoted physical table name.
     * @param   string             $column  Timestamp column the maximum is taken over.
     * @param   DateTimeImmutable  $now     Reading the age is measured against.
     *
     * @return  float  Age in seconds, or zero when the table is empty.
     *
     * @since   2.0.0
     */
    private function youngest(string $table, string $column, DateTimeImmutable $now): float
    {
        $newest = $this->database->fetchOne(sprintf('SELECT MAX(%s) FROM %s', $column, $table));

        return $this->elapsed($newest, $now);
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
