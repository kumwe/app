<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Retention;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Retention\RetentionCatalogue;
use Kumwe\App\Application\Retention\RetentionObservation;
use Kumwe\App\Application\Retention\RetentionObserver;
use Kumwe\App\Application\Retention\RetentionPolicy;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;

/**
 * Reads the six retention metrics of every store with a fixed number of bounded indexed probes.
 *
 * Nothing here is an exact aggregate over a hot ledger. A backlog is a count over a derived table
 * capped at the probe limit, so the statement examines at most that many index entries however large
 * the ledger is, and the observation says when the cap was hit. An oldest age is the first entry of an
 * ascending index range. The ingest and expiry rates are capped counts over a five-minute trailing and
 * leading window on the store's arrival and eligibility columns, which the retention migration indexes.
 * The drain rate comes from the run ledger, never from the ledger being drained. Every probe is
 * therefore a bounded index range, which is what lets this run on every scrape and readiness poll.
 *
 * @since  2.0.0
 */
final readonly class DoctrineRetentionObserver implements RetentionObserver
{
    /**
     * Most index entries any one probe examines before reporting the count as approximate.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int PROBE_CAP = 100_000;

    /**
     * Width of the trailing ingest window and the leading expiry window.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int WINDOW_SECONDS = 300;

    /**
     * Bind the observer to the database, the catalogue and the run ledger.
     *
     * @param  Connection          $database   Connection the probes run on.
     * @param  TableNames          $tables     Prefixed physical table names.
     * @param  ClockInterface      $clock      Instant every window and age is measured against.
     * @param  RetentionCatalogue  $catalogue  Declared windows, budgets and required settings.
     * @param  RetentionRunLedger  $runs       Source of the drain rate.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
        private RetentionCatalogue $catalogue,
        private RetentionRunLedger $runs,
    ) {
    }

    /**
     * Observe one store.
     *
     * @param   RetentionStore  $store  Store to observe.
     *
     * @return  RetentionObservation  The six metrics with their qualifying flags.
     *
     * @since   2.0.0
     */
    public function observe(RetentionStore $store): RetentionObservation
    {
        return $this->observeWith($store, $this->schedules());
    }

    /**
     * Observe every declared store, reading the schedule settings once.
     *
     * @return  list<RetentionObservation>  One observation per catalogue entry.
     *
     * @since   2.0.0
     */
    public function observeAll(): array
    {
        $schedules = $this->schedules();
        $observations = [];
        foreach ($this->catalogue->policies() as $policy) {
            $observations[] = $this->observeWith($policy->store, $schedules);
        }

        return $observations;
    }

    /**
     * Observe one store against already-read schedule settings.
     *
     * @param RetentionStore $store Store to observe.
     * @param   list<array{job_type: string, enabled: bool, payload: array<string, mixed>}>  $schedules  Settings.
     *
     * @return  RetentionObservation  The observation.
     *
     * @since   2.0.0
     */
    private function observeWith(RetentionStore $store, array $schedules): RetentionObservation
    {
        $policy = $this->catalogue->policy($store);
        $now = $this->clock->now();
        $problems = $this->settingProblems($policy, $schedules);
        $retentionSeconds = $store === RetentionStore::Audit
            ? $this->auditRetentionDays($schedules) * 86_400
            : $policy->minimumRetentionSeconds;
        $probe = $policy->drainable() && ($store !== RetentionStore::Audit || $retentionSeconds > 0)
            ? $this->probe($store, $now, $retentionSeconds)
            : null;
        [$backlog, $approximate] = $probe === null ? [0, false] : $this->boundedCount(
            $probe['table'],
            $probe['eligible'],
            $probe['eligible_parameters'],
            $probe['eligible_types'],
        );
        $oldest = $probe === null ? null : $this->oldest(
            $probe['table'],
            $probe['age_column'],
            $probe['eligible'],
            $probe['eligible_parameters'],
            $probe['eligible_types'],
            $now,
        );
        $ingestColumn = $this->ingestColumn($store);
        $ingest = $ingestColumn === null ? null : $this->boundedCount(
            $this->table($store),
            sprintf('%s > ?', $ingestColumn),
            [$now->sub(new DateInterval(sprintf('PT%dS', self::WINDOW_SECONDS)))],
            [Types::DATETIME_IMMUTABLE],
        )[0] / self::WINDOW_SECONDS;
        $expiry = $probe === null ? null : $this->boundedCount(
            $probe['table'],
            $probe['expiry_window'],
            $probe['expiry_parameters'],
            $probe['expiry_types'],
        )[0] / self::WINDOW_SECONDS;
        $run = $this->runs->latest($store);
        $drain = $run === null || $run['rows_drained'] < 1 || $run['elapsed_ms'] < 1
            ? null
            : $run['rows_drained'] / ($run['elapsed_ms'] / 1_000);

        return new RetentionObservation(
            $store,
            $now,
            $ingest,
            $expiry,
            $drain,
            $backlog,
            $approximate,
            $oldest,
            RetentionObservation::forecast($backlog, $policy->capacityRows, $ingest, $drain, $policy->dutyCycle()),
            $policy->capacityRows,
            $problems === [],
            $problems,
            $run['ran_at'] ?? null,
        );
    }

    /**
     * Describe the eligibility probe of one drainable store.
     *
     * @param   RetentionStore     $store             Store to describe.
     * @param   DateTimeImmutable  $now               Instant of the observation.
     * @param   int                $retentionSeconds  Window rows must outlive before eligibility.
     *
     * @return  array{table: string, eligible: string, eligible_parameters: list<mixed>, eligible_types: list<string>,
     *          age_column: string, expiry_window: string, expiry_parameters: list<mixed>, expiry_types: list<string>}
     *          Probe description.
     *
     * @since   2.0.0
     */
    private function probe(RetentionStore $store, DateTimeImmutable $now, int $retentionSeconds): array
    {
        $cutoff = $now->sub(new DateInterval(sprintf('PT%dS', $retentionSeconds)));
        $window = new DateInterval(sprintf('PT%dS', self::WINDOW_SECONDS));
        $timestamps = [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE];
        $table = $this->table($store);

        return match ($store) {
            RetentionStore::BusinessIdempotency => [
                'table' => $table,
                'eligible' => "expires_at <= ? AND (state = 'completed' OR (state = 'in_progress' "
                    . 'AND (lease_expires_at IS NULL OR lease_expires_at <= ?)))',
                'eligible_parameters' => [$now, $now],
                'eligible_types' => $timestamps,
                'age_column' => 'expires_at',
                'expiry_window' => 'expires_at > ? AND expires_at <= ?',
                'expiry_parameters' => [$now, $now->add($window)],
                'expiry_types' => $timestamps,
            ],
            RetentionStore::DeliveryIdempotency => [
                'table' => $table,
                'eligible' => "expires_at <= ? AND ((state IN ('completed', 'failed') AND owner_token IS NULL) "
                    . "OR (state = 'in_progress' AND (locked_until IS NULL OR locked_until <= ?)))",
                'eligible_parameters' => [$now, $now],
                'eligible_types' => $timestamps,
                'age_column' => 'expires_at',
                'expiry_window' => 'expires_at > ? AND expires_at <= ?',
                'expiry_parameters' => [$now, $now->add($window)],
                'expiry_types' => $timestamps,
            ],
            RetentionStore::OutboxSourceEvents => [
                'table' => $table,
                'eligible' => "retained_until <= ? AND status IN ('dispatched', 'dead')",
                'eligible_parameters' => [$now],
                'eligible_types' => [Types::DATETIME_IMMUTABLE],
                'age_column' => 'retained_until',
                'expiry_window' => 'retained_until > ? AND retained_until <= ?',
                'expiry_parameters' => [$now, $now->add($window)],
                'expiry_types' => $timestamps,
            ],
            RetentionStore::SequencedJournal => [
                'table' => $table,
                'eligible' => 'recorded_at <= ?',
                'eligible_parameters' => [$cutoff],
                'eligible_types' => [Types::DATETIME_IMMUTABLE],
                'age_column' => 'recorded_at',
                'expiry_window' => 'recorded_at > ? AND recorded_at <= ?',
                'expiry_parameters' => [$cutoff, $cutoff->add($window)],
                'expiry_types' => $timestamps,
            ],
            RetentionStore::InboxReceipts => [
                'table' => $table,
                'eligible' => "status IN ('completed', 'poison', 'unavailable') AND updated_at <= ?",
                'eligible_parameters' => [$cutoff],
                'eligible_types' => [Types::DATETIME_IMMUTABLE],
                'age_column' => 'updated_at',
                'expiry_window' => "status IN ('completed', 'poison', 'unavailable') AND updated_at > ? "
                    . 'AND updated_at <= ?',
                'expiry_parameters' => [$cutoff, $cutoff->add($window)],
                'expiry_types' => $timestamps,
            ],
            RetentionStore::JobHistory => [
                'table' => $table,
                'eligible' => "status = 'completed' AND completed_at <= ?",
                'eligible_parameters' => [$cutoff],
                'eligible_types' => [Types::DATETIME_IMMUTABLE],
                'age_column' => 'completed_at',
                'expiry_window' => "status = 'completed' AND completed_at > ? AND completed_at <= ?",
                'expiry_parameters' => [$cutoff, $cutoff->add($window)],
                'expiry_types' => $timestamps,
            ],
            RetentionStore::ProcessHistory => [
                'table' => $table,
                'eligible' => "status IN ('completed', 'dead', 'canceled') AND updated_at <= ?",
                'eligible_parameters' => [$cutoff],
                'eligible_types' => [Types::DATETIME_IMMUTABLE],
                'age_column' => 'updated_at',
                'expiry_window' => "status IN ('completed', 'dead', 'canceled') AND updated_at > ? "
                    . 'AND updated_at <= ?',
                'expiry_parameters' => [$cutoff, $cutoff->add($window)],
                'expiry_types' => $timestamps,
            ],
            RetentionStore::ExportArtifacts => [
                'table' => $table,
                'eligible' => 'expires_at <= ?',
                'eligible_parameters' => [$now],
                'eligible_types' => [Types::DATETIME_IMMUTABLE],
                'age_column' => 'expires_at',
                'expiry_window' => 'expires_at > ? AND expires_at <= ?',
                'expiry_parameters' => [$now, $now->add($window)],
                'expiry_types' => $timestamps,
            ],
            RetentionStore::Audit => [
                'table' => $table,
                'eligible' => 'occurred_at <= ?',
                'eligible_parameters' => [$cutoff],
                'eligible_types' => [Types::DATETIME_IMMUTABLE],
                'age_column' => 'occurred_at',
                'expiry_window' => 'occurred_at > ? AND occurred_at <= ?',
                'expiry_parameters' => [$cutoff, $cutoff->add($window)],
                'expiry_types' => $timestamps,
            ],
            RetentionStore::Sessions => [
                'table' => $table,
                'eligible' => 'expires_at <= ?',
                'eligible_parameters' => [$now],
                'eligible_types' => [Types::DATETIME_IMMUTABLE],
                'age_column' => 'expires_at',
                'expiry_window' => 'expires_at > ? AND expires_at <= ?',
                'expiry_parameters' => [$now, $now->add($window)],
                'expiry_types' => $timestamps,
            ],
            RetentionStore::Revisions => [
                'table' => $table,
                'eligible' => '1 = 0',
                'eligible_parameters' => [],
                'eligible_types' => [],
                'age_column' => 'created_at',
                'expiry_window' => '1 = 0',
                'expiry_parameters' => [],
                'expiry_types' => [],
            ],
        };
    }

    /**
     * Quoted physical table of a store.
     *
     * @param   RetentionStore  $store  Store to resolve.
     *
     * @return  string  Quoted table name.
     *
     * @since   2.0.0
     */
    private function table(RetentionStore $store): string
    {
        return $this->tables->quoted(self::physicalTable($store));
    }

    /**
     * Unprefixed logical table name of a store, shared with the exact census.
     *
     * @param   RetentionStore  $store  Store to resolve.
     *
     * @return  string  Logical table name the installation prefix is applied to.
     *
     * @since   2.0.0
     */
    public static function physicalTable(RetentionStore $store): string
    {
        return match ($store) {
            RetentionStore::BusinessIdempotency => 'business_command_idempotency',
            RetentionStore::DeliveryIdempotency => 'idempotency',
            RetentionStore::Revisions => 'business_record_revisions',
            RetentionStore::OutboxSourceEvents => 'integration_outbox',
            RetentionStore::SequencedJournal => 'business_projection_source_events',
            RetentionStore::InboxReceipts => 'integration_inbox',
            RetentionStore::JobHistory => 'jobs',
            RetentionStore::ProcessHistory => 'business_process_work',
            RetentionStore::ExportArtifacts => 'business_report_export_artifacts',
            RetentionStore::Audit => 'audit_events',
            RetentionStore::Sessions => 'administrator_sessions',
        };
    }

    /**
     * Indexed arrival column of a store, for the ingest probe.
     *
     * @param   RetentionStore  $store  Store to resolve.
     *
     * @return  ?string  Column name, or null when the store records no arrival instant.
     *
     * @since   2.0.0
     */
    private function ingestColumn(RetentionStore $store): ?string
    {
        return match ($store) {
            RetentionStore::BusinessIdempotency, RetentionStore::DeliveryIdempotency, RetentionStore::Revisions,
            RetentionStore::OutboxSourceEvents, RetentionStore::JobHistory, RetentionStore::ProcessHistory,
            RetentionStore::Sessions => 'created_at',
            RetentionStore::SequencedJournal => 'recorded_at',
            RetentionStore::InboxReceipts => 'first_received_at',
            RetentionStore::Audit => 'occurred_at',
            RetentionStore::ExportArtifacts => null,
        };
    }

    /**
     * Count matching rows up to the probe cap.
     *
     * @param   string        $table       Quoted table.
     * @param   string        $predicate   Predicate built only from literals in this class.
     * @param   list<mixed>   $parameters  Bound values.
     * @param   list<string>  $types       Bound types.
     *
     * @return  array{int, bool}  Count and whether it hit the cap.
     *
     * @since   2.0.0
     */
    private function boundedCount(string $table, string $predicate, array $parameters, array $types): array
    {
        $value = $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM (SELECT 1 AS probe FROM %s WHERE %s LIMIT %d) bounded',
            $table,
            $predicate,
            self::PROBE_CAP,
        ), $parameters, $types);
        $count = is_int($value) ? $value : (is_string($value) && is_numeric($value) ? (int) $value : 0);

        return [$count, $count >= self::PROBE_CAP];
    }

    /**
     * Age of the oldest eligible row, from the first entry of an ascending index range.
     *
     * @param   string             $table       Quoted table.
     * @param   string             $column      Indexed column the range is ordered by.
     * @param   string             $predicate   Eligibility predicate.
     * @param   list<mixed>        $parameters  Bound values.
     * @param   list<string>       $types       Bound types.
     * @param   DateTimeImmutable  $now         Instant the age is measured against.
     *
     * @return  ?float  Age in seconds, or null when nothing is eligible.
     *
     * @since   2.0.0
     */
    private function oldest(
        string $table,
        string $column,
        string $predicate,
        array $parameters,
        array $types,
        DateTimeImmutable $now,
    ): ?float {
        $value = $this->database->fetchOne(
            sprintf('SELECT %s FROM %s WHERE %s ORDER BY %s LIMIT 1', $column, $table, $predicate, $column),
            $parameters,
            $types,
        );
        $parsed = $value instanceof DateTimeImmutable
            ? $value
            : (is_string($value) && $value !== '' ? date_create_immutable($value) : false);
        if ($parsed === false) {
            return null;
        }

        return max(0.0, (float) ($now->getTimestamp() - $parsed->getTimestamp()));
    }

    /**
     * Read the maintenance schedules the catalogue's required settings refer to.
     *
     * @return  list<array{job_type: string, enabled: bool, payload: array<string, mixed>}>  Settings.
     *
     * @since   2.0.0
     */
    private function schedules(): array
    {
        $types = [];
        foreach ($this->catalogue->policies() as $policy) {
            foreach ($policy->requiredSettings as $setting) {
                $parts = explode(':', $setting);
                $types[$parts[1] ?? ''] = true;
            }
        }
        unset($types['']);
        if ($types === []) {
            return [];
        }
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT job_type, enabled, payload FROM %s WHERE job_type IN (?)',
            $this->tables->quoted('schedules'),
        ), [array_keys($types)], [\Doctrine\DBAL\ArrayParameterType::STRING]);
        $schedules = [];
        foreach ($rows as $row) {
            $payload = $row['payload'] ?? null;
            $decoded = is_string($payload) ? json_decode($payload, true) : $payload;
            $jobType = $row['job_type'] ?? null;
            if (!is_string($jobType)) {
                continue;
            }
            /** @var array<string, mixed> $document */
            $document = is_array($decoded) ? $decoded : [];
            $schedules[] = [
                'job_type' => $jobType,
                'enabled' => in_array($row['enabled'] ?? null, [true, 1, '1', 't'], true),
                'payload' => $document,
            ];
        }

        return $schedules;
    }

    /**
     * Name every required setting of a policy that is absent, disabled or unconfigured.
     *
     * @param   RetentionPolicy                                                              $policy     Policy.
     * @param   list<array{job_type: string, enabled: bool, payload: array<string, mixed>}>  $schedules  Settings.
     *
     * @return  list<string>  Problems, empty when configured.
     *
     * @since   2.0.0
     */
    private function settingProblems(RetentionPolicy $policy, array $schedules): array
    {
        $problems = [];
        foreach ($policy->requiredSettings as $setting) {
            $parts = explode(':', $setting);
            $kind = $parts[0];
            $jobType = $parts[1] ?? '';
            $qualifier = $parts[2] ?? null;
            $satisfied = false;
            foreach ($schedules as $schedule) {
                if ($schedule['job_type'] !== $jobType) {
                    continue;
                }
                if ($kind === 'schedule') {
                    $storeMatches = $qualifier === null || ($schedule['payload']['store'] ?? null) === $qualifier;
                    $satisfied = $satisfied || ($schedule['enabled'] && $storeMatches);
                } elseif ($kind === 'payload' && $qualifier !== null) {
                    $value = $schedule['payload'][$qualifier] ?? 0;
                    $satisfied = $satisfied || ($schedule['enabled'] && is_int($value) && $value > 0);
                }
            }
            if (!$satisfied) {
                $problems[] = $setting . ' is absent or disabled';
            }
        }

        return $problems;
    }

    /**
     * Read the configured audit retention window in days.
     *
     * @param   list<array{job_type: string, enabled: bool, payload: array<string, mixed>}>  $schedules  Settings.
     *
     * @return  int  Retention days, or zero when unconfigured.
     *
     * @since   2.0.0
     */
    private function auditRetentionDays(array $schedules): int
    {
        foreach ($schedules as $schedule) {
            if ($schedule['job_type'] === 'audit.retention.enforce' && $schedule['enabled']) {
                $days = $schedule['payload']['retention_days'] ?? 0;

                return is_int($days) && $days > 0 ? $days : 0;
            }
        }

        return 0;
    }
}
