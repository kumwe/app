<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use InvalidArgumentException;
use Kumwe\Access\AuthorizationGateway;
use Kumwe\Access\AuthorizationResource;
use Kumwe\Access\Capability;
use Kumwe\App\Application\Diagnostics\OperatorDiagnostics;
use Kumwe\App\Application\Retention\RetentionCatalogue;
use Kumwe\App\Application\Retention\RetentionObserver;
use Kumwe\App\Infrastructure\Persistence\BoundedStatementExecutor;
use Kumwe\App\Infrastructure\Persistence\StatementBudget;
use Kumwe\App\Infrastructure\Persistence\StatementBudgetExceeded;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Infrastructure\Retention\DoctrineRetentionObserver;
use Kumwe\BusinessSchema\Domain\PhysicalNameCompiler;
use Kumwe\Context\Value\ExecutionContext;
use Psr\Clock\ClockInterface;

/**
 * Costed operator probes over existing engine statistics, queue rows and retention observations.
 *
 * Engine privileges and optional statement statistics differ across installations. A missing source is
 * explicitly unavailable, never a healthy zero. SQL text, identities and payloads never leave this reader.
 *
 * @since  2.0.0
 */
final readonly class DoctrineOperatorDiagnostics implements OperatorDiagnostics
{
    /**
     * Maximum published rows per source; sampled queue depth remains a lower bound.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int ROW_LIMIT = 20;

    /**
     * Bind the existing authorization, bounded SQL and retention services.
     *
     * @param  Connection            $database       Authoritative primary connection.
     * @param  TableNames            $tables         Installation table names.
     * @param  AuthorizationGateway  $authorization  Shared installation-wide authority.
     * @param  RetentionObserver     $retention      Existing bounded retention measurements.
     * @param  RetentionCatalogue    $catalogue      Sustained drain duty cycles.
     * @param  PhysicalNameCompiler  $names          Canonical definition-to-table naming authority.
     * @param  ClockInterface        $clock          Snapshot timestamp.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private AuthorizationGateway $authorization,
        private RetentionObserver $retention,
        private RetentionCatalogue $catalogue,
        private PhysicalNameCompiler $names,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Authorize before selecting the bounded source; sanitize driver failures at this diagnostic boundary.
     *
     * @param   ExecutionContext  $context  Operator identity and provenance.
     * @param   string            $section  Fixed question to answer.
     *
     * @return  array<string, mixed>  Availability, cost and scalar rows; no raw SQL or driver errors.
     *
     * @throws  \Kumwe\Access\AuthorizationDenied  When operator authority is absent.
     * @throws  InvalidArgumentException  When the section is unknown.
     *
     * @since   2.0.0
     */
    public function read(ExecutionContext $context, string $section = 'queues'): array
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString(self::CAPABILITY),
            AuthorizationResource::collection('operator_diagnostics'),
        );
        if (!in_array($section, self::SECTIONS, true)) {
            throw new InvalidArgumentException('The diagnostic section is not supported.');
        }
        $document = [
            'section' => $section,
            'observed_at' => $this->clock->now()->format(DATE_ATOM),
            'status' => 'available',
            'cost_class' => in_array($section, ['contention', 'slow'], true) ? 'engine_statistics' : 'bounded_probes',
            'statement_timeout_ms' => 1000,
            'statement_byte_limit' => 262144,
            'row_limit_per_source' => self::ROW_LIMIT,
            'sample_limit_per_source' => in_array($section, ['backlog', 'retention'], true)
                ? DoctrineRetentionObserver::PROBE_CAP : RuntimeMetricCollector::PROBE_CAP,
            'definition_catalogue_limit' => $section === 'slow' ? 1000 : null,
            'statement_digest_limit' => $section === 'slow' ? 100 : null,
            'rows' => [],
        ];
        try {
            $document['rows'] = match ($section) {
                'contention' => $this->contention(),
                'queues' => $this->queues(),
                'slow' => $this->slow(),
                'backlog', 'retention' => $this->retention($section),
            };
        } catch (StatementBudgetExceeded) {
            $document['status'] = 'budget_exceeded';
        } catch (DbalException) {
            // Missing engine statistics/privileges or an unavailable database must not disclose SQL or credentials.
            $document['status'] = 'unavailable';
        }

        return $document;
    }

    /**
     * Read lock wait classes without publishing transaction, connection or SQL identities.
     *
     * @return  list<array<string, mixed>>  At most twenty engine lock-wait groups.
     *
     * @since   2.0.0
     */
    private function contention(): array
    {
        $platform = $this->database->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            $sql = "SELECT COALESCE(c.relname, 'transaction') AS table_name, l.mode AS lock_class, "
                . 'COUNT(*) AS waiting FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid '
                . 'LEFT JOIN pg_class c ON c.oid = l.relation '
                . 'WHERE NOT l.granted AND a.datname = current_database() '
                . 'GROUP BY c.relname, l.mode ORDER BY waiting DESC LIMIT 20';
        } elseif ($platform instanceof MariaDBPlatform) {
            $sql = 'SELECT l.lock_table AS table_name, l.lock_mode AS lock_class, COUNT(*) AS waiting '
                . 'FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_LOCKS l '
                . 'ON l.lock_id = w.requested_lock_id '
                . "WHERE LOCATE(CONCAT('`', REPLACE(DATABASE(), '`', '``'), '`.`'), l.lock_table) = 1 "
                . 'GROUP BY l.lock_table, l.lock_mode '
                . 'ORDER BY waiting DESC LIMIT 20';
        } else {
            $sql = 'SELECT l.OBJECT_NAME AS table_name, l.LOCK_MODE AS lock_class, COUNT(*) AS waiting '
                . 'FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l '
                . 'ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE = w.ENGINE '
                . 'WHERE l.OBJECT_SCHEMA = DATABASE() GROUP BY l.OBJECT_NAME, l.LOCK_MODE '
                . 'ORDER BY waiting DESC LIMIT 20';
        }

        return $this->query($sql);
    }

    /**
     * Group the oldest bounded active slice by registered queue, event stream and consumer.
     *
     * @return  list<array<string, mixed>>  At most twenty groups per each of three fixed sources.
     *
     * @since   2.0.0
     */
    private function queues(): array
    {
        $rows = [];
        foreach (
            [
            'jobs' => ['queue', 'created_at'],
            'integration_outbox' => ['event_type', 'created_at'],
            'integration_inbox' => ['consumer_id', 'first_received_at'],
            ] as $source => [$group, $instant]
        ) {
            $sample = $this->query(sprintf(
                'SELECT stream, COUNT(*) AS depth, MIN(observed) AS oldest FROM '
                . '(SELECT %s AS stream, %s AS observed FROM %s '
                . "WHERE status IN ('pending', 'reserved') ORDER BY %s LIMIT %d) sampled "
                . 'GROUP BY stream ORDER BY oldest LIMIT 20',
                $group,
                $instant,
                $this->tables->quoted($source),
                $instant,
                RuntimeMetricCollector::PROBE_CAP,
            ));
            foreach ($sample as $row) {
                $oldest = $row['oldest'] ?? null;
                $date = is_string($oldest) ? date_create_immutable($oldest) : false;
                $rows[] = [
                    'source' => $source,
                    'stream' => $row['stream'] ?? null,
                    'depth_lower_bound' => $row['depth'] ?? null,
                    'oldest_age_seconds' => $date === false
                        ? null : max(0, $this->clock->now()->getTimestamp() - $date->getTimestamp()),
                ];
            }
        }

        return $rows;
    }

    /**
     * Rank current definition queries from engine-measured statement digests, including compiled policy cost.
     *
     * The first thousand catalogue heads and hundred engine digests are explicit samples. Native statement
     * statistics must be enabled by the operator; no background profiler or unbounded metric labels are added.
     *
     * @return  list<array<string, mixed>>  At most twenty definition cost observations; raw SQL is discarded.
     *
     * @since   2.0.0
     */
    private function slow(): array
    {
        $definitions = $this->query(sprintf(
            'SELECT id, handle FROM %s ORDER BY id LIMIT 1000',
            $this->tables->quoted('business_definitions'),
        ));
        $known = [];
        foreach ($definitions as $definition) {
            if (is_string($definition['id'] ?? null) && is_string($definition['handle'] ?? null)) {
                $known[$this->names->entityTable($definition['id'], $definition['handle'])] = $definition['handle'];
            }
        }
        $sql = $this->database->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? 'SELECT LEFT(query, 1024) AS statement, calls, mean_exec_time AS mean_ms, total_exec_time AS total_ms '
                . 'FROM pg_stat_statements WHERE dbid = '
                . '(SELECT oid FROM pg_database WHERE datname = current_database()) '
                . 'ORDER BY total_exec_time DESC LIMIT 100'
            : 'SELECT LEFT(DIGEST_TEXT, 1024) AS statement, COUNT_STAR AS calls, '
                . 'AVG_TIMER_WAIT / 1000000000 AS mean_ms, '
                . 'SUM_TIMER_WAIT / 1000000000 AS total_ms '
                . 'FROM performance_schema.events_statements_summary_by_digest WHERE SCHEMA_NAME = DATABASE() '
                . 'ORDER BY SUM_TIMER_WAIT DESC LIMIT 100';
        $rows = [];
        foreach ($this->query($sql) as $sample) {
            $statement = $sample['statement'] ?? null;
            if (!is_string($statement)) {
                continue;
            }
            foreach ($known as $table => $definition) {
                if (preg_match('/(?<![a-z0-9_])' . preg_quote($table, '/') . '(?![a-z0-9_])/i', $statement) !== 1) {
                    continue;
                }
                $rows[] = [
                    'definition' => $definition,
                    'policy_cost' => 'included_in_statement',
                    'calls' => $sample['calls'] ?? null,
                    'mean_ms' => $sample['mean_ms'] ?? null,
                    'total_ms' => $sample['total_ms'] ?? null,
                ];
                if (count($rows) >= self::ROW_LIMIT) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    /**
     * Project the existing retention measurements, keeping unknown rates distinct from zero.
     *
     * @param   string  $section  Backlog or retention projection.
     *
     * @return  list<array<string, mixed>>  One bounded row per declared hot ledger.
     *
     * @since   2.0.0
     */
    private function retention(string $section): array
    {
        $rows = [];
        foreach ($this->retention->observeAll() as $observation) {
            $duty = $this->catalogue->policy($observation->store)->dutyCycle();
            $drain = $observation->drainRowsPerSecond === null ? null : $observation->drainRowsPerSecond * $duty;
            $slope = $observation->ingestRowsPerSecond === null || $drain === null
                ? null : $observation->ingestRowsPerSecond - $drain;
            $seconds = $observation->forecastSecondsToCapacity;
            $rows[] = [
                'store' => $observation->store->value,
                'backlog_rows' => $observation->backlogRows,
                'backlog_approximate' => $observation->backlogApproximate,
                'ingest_rows_per_second' => $observation->ingestRowsPerSecond,
                'sustained_drain_rows_per_second' => $drain,
                'net_growth_rows_per_second' => $slope,
                'capacity_rows' => $observation->capacityRows,
                'forecast_seconds_to_capacity' => $seconds,
                'configured' => $observation->configured,
                'last_drain_at' => $observation->lastDrainAt?->format(DATE_ATOM),
            ];
            if ($section === 'retention') {
                $rows[array_key_last($rows)]['expiry_rows_per_second'] = $observation->expiryRowsPerSecond;
            }
        }

        return $rows;
    }

    /**
     * Apply the same server timeout and materialized byte budget to every directly owned probe.
     *
     * @param   string  $sql  Constant SELECT assembled exclusively from fixed vocabulary.
     *
     * @return  list<array<string, mixed>>  Rows under the one-second and 256 KiB bounds.
     *
     * @since   2.0.0
     */
    private function query(string $sql): array
    {
        return (new BoundedStatementExecutor($this->database))->fetchAll(
            $sql,
            [],
            [],
            new StatementBudget(1000, 262144),
        );
    }
}
