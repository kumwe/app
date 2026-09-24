<?php

/**
 * Measure storage and write amplification per logical transaction and forecast growth (P5-I).
 *
 * Usage: php tools/perf-storage.php [--records=500]
 *
 * Creates the requested number of ordinary records through the production `BusinessRecordService` on the
 * configured disposable test database (one logical business transaction each) and measures, per LBT:
 * physical row mutations (MariaDB session `Handler_write/update/delete`, PostgreSQL `pg_stat_database`
 * tuple counters after a forced statistics flush), table and index bytes by table, write-ahead log bytes
 * (PostgreSQL LSN distance, MariaDB binary log position), and undo pressure (InnoDB history list length,
 * PostgreSQL dead tuples). It then publishes the daily, monthly and yearly forecast at the five-million
 * LBT enterprise target, the 30% free-space reserve and the temporary space for the largest rebuild.
 * Requires APP_ENV=testing. Writes build/perf/storage-<engine>.json.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Ramsey\Uuid\Uuid;

if (getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "The storage benchmark requires APP_ENV=testing and a disposable test database.\n");
    exit(2);
}
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$records = 500;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--records=(\d+)$/D', $argument, $match) !== 1 || (int) $match[1] < 10) {
        fwrite(STDERR, "Usage: php tools/perf-storage.php [--records=N>=10]\n");
        exit(2);
    }
    $records = (int) $match[1];
}
$container = TestKernelFactory::create(Environment::fromGlobals());
$context = TestKernelFactory::administratorContext($container);
$service = $container->get(BusinessRecordService::class);
$database = $container->get(Connection::class);
$tables = $container->get(TableNames::class);
if (!$service instanceof BusinessRecordService || !$database instanceof Connection || !$tables instanceof TableNames) {
    throw new RuntimeException('The storage benchmark services are unavailable.');
}
$postgres = $database->getDatabasePlatform() instanceof PostgreSQLPlatform;
$engine = $postgres ? 'pgsql' : 'mariadb';
$prefix = $tables->prefix();

/**
 * Snapshot every measured counter.
 *
 * @param   Connection  $database  The session the records are written on.
 * @param   bool        $postgres  Whether the engine is PostgreSQL.
 * @param   string      $prefix    Installation table prefix.
 *
 * @return  array{mutations: int, wal_bytes: ?int, undo: ?int, tables: array<string, array{data: int, index: int}>}
 *
 * @since   2.0.0
 */
function storageSnapshot(Connection $database, bool $postgres, string $prefix): array
{
    $tables = [];
    if ($postgres) {
        $database->executeStatement('SELECT pg_stat_force_next_flush()');
        usleep(1_100_000);
        $stats = $database->fetchAssociative(
            'SELECT tup_inserted + tup_updated + tup_deleted AS mutations FROM pg_stat_database '
            . 'WHERE datname = current_database()',
        );
        $wal = $database->fetchOne("SELECT pg_wal_lsn_diff(pg_current_wal_lsn(), '0/0')::bigint");
        $undo = $database->fetchOne(
            "SELECT COALESCE(SUM(n_dead_tup), 0) FROM pg_stat_user_tables WHERE relname LIKE ?",
            [$prefix . '%'],
        );
        foreach (
            $database->fetchAllAssociative(
                "SELECT c.relname AS name, pg_relation_size(c.oid) AS data, pg_indexes_size(c.oid) AS idx "
                . "FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relkind = 'r' "
                . "AND n.nspname = current_schema() AND c.relname LIKE ?",
                [$prefix . '%'],
            ) as $row
        ) {
            $tables[(string) $row['name']] = ['data' => (int) $row['data'], 'index' => (int) $row['idx']];
        }

        return [
            'mutations' => (int) ($stats['mutations'] ?? 0),
            'wal_bytes' => (int) $wal,
            'undo' => (int) $undo,
            'tables' => $tables,
        ];
    }
    $handlers = 0;
    foreach (
        $database->fetchAllAssociative(
            "SHOW SESSION STATUS WHERE Variable_name IN ('Handler_write', 'Handler_update', 'Handler_delete')",
        ) as $row
    ) {
        $handlers += (int) array_values($row)[1];
    }
    $binlog = null;
    try {
        $status = $database->fetchAssociative('SHOW BINARY LOG STATUS');
    } catch (Throwable) {
        try {
            $status = $database->fetchAssociative('SHOW MASTER STATUS');
        } catch (Throwable) {
            $status = false;
        }
    }
    if (is_array($status)) {
        $binlog = ['file' => (string) ($status['File'] ?? ''), 'position' => (int) ($status['Position'] ?? 0)];
    }
    $history = $database->fetchAssociative("SHOW GLOBAL STATUS LIKE 'Innodb_history_list_length'");
    foreach (
        $database->fetchAllAssociative(
            'SELECT table_name AS name, data_length AS data, index_length AS idx FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name LIKE ?',
            [$prefix . '%'],
        ) as $row
    ) {
        $tables[(string) $row['name']] = ['data' => (int) $row['data'], 'index' => (int) $row['idx']];
    }

    return [
        'mutations' => $handlers,
        'wal_bytes' => $binlog === null ? null : $binlog['position'],
        'wal_file' => $binlog['file'] ?? null,
        'undo' => is_array($history) ? (int) array_values($history)[1] : null,
        'tables' => $tables,
    ];
}

$definition = NeutralBusinessFixture::install(
    $container,
    $context,
    NeutralBusinessFixture::document('storage' . bin2hex(random_bytes(3)), Uuid::uuid7()->toString()),
);
$analyse = static function () use ($database, $postgres, $prefix): void {
    if ($postgres) {
        $database->executeStatement('ANALYZE');
        return;
    }
    $names = $database->fetchFirstColumn(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?',
        [$prefix . '%'],
    );
    foreach (array_chunk($names, 20) as $chunk) {
        $database->fetchAllAssociative('ANALYZE TABLE ' . implode(', ', array_map(
            static fn (mixed $name): string => $database->quoteSingleIdentifier((string) $name),
            $chunk,
        )));
    }
};
$analyse();
$before = storageSnapshot($database, $postgres, $prefix);
$started = hrtime(true);
for ($index = 0; $index < $records; $index++) {
    $service->create(new CreateRecordCommand(
        $context,
        $definition->handle,
        NeutralBusinessFixture::recordValues('Storage sample ' . $index),
        NeutralBusinessFixture::idempotencyKey('storage-' . bin2hex(random_bytes(8))),
        recordId: Uuid::uuid7()->toString(),
    ));
}
$elapsed = (hrtime(true) - $started) / 1_000_000_000;
$analyse();
$after = storageSnapshot($database, $postgres, $prefix);

$growth = [];
$dataBytes = 0;
$indexBytes = 0;
foreach ($after['tables'] as $name => $size) {
    $old = $before['tables'][$name] ?? ['data' => 0, 'index' => 0];
    $data = $size['data'] - $old['data'];
    $index = $size['index'] - $old['index'];
    if ($data !== 0 || $index !== 0) {
        $growth[substr($name, strlen($prefix))] = [
            'data_bytes_per_lbt' => round($data / $records, 1),
            'index_bytes_per_lbt' => round($index / $records, 1),
        ];
    }
    $dataBytes += $data;
    $indexBytes += $index;
}
arsort($growth);
$walPerLbt = $before['wal_bytes'] === null || $after['wal_bytes'] === null
    || ($before['wal_file'] ?? null) !== ($after['wal_file'] ?? null)
    ? null
    : ($after['wal_bytes'] - $before['wal_bytes']) / $records;
$bytesPerLbt = ($dataBytes + $indexBytes) / $records;
$daily = 5_000_000;
$largest = 0;
foreach ($after['tables'] as $size) {
    $largest = max($largest, $size['data'] + $size['index']);
}
$report = [
    'tool' => 'kumwe-storage-forecast',
    'engine' => $engine,
    'database_version' => (string) $database->fetchOne('SELECT VERSION()'),
    'measured_at' => gmdate('c'),
    'records' => $records,
    'elapsed_seconds' => round($elapsed, 2),
    'measured' => [
        'physical_row_mutations_per_lbt' => round(($after['mutations'] - $before['mutations']) / $records, 2),
        'physical_row_mutations_source' => $postgres
            ? 'pg_stat_database tup_inserted+tup_updated+tup_deleted (database-wide, forced flush)'
            : 'session Handler_write+Handler_update+Handler_delete (includes internal temporary tables)',
        'table_bytes_per_lbt' => round($dataBytes / $records, 1),
        'index_bytes_per_lbt' => round($indexBytes / $records, 1),
        'log_bytes_per_lbt' => $walPerLbt === null ? null : round($walPerLbt, 1),
        'log_kind' => $postgres ? 'write-ahead log (LSN distance)' : 'binary log position distance',
        'undo_indicator_delta' => $after['undo'] === null || $before['undo'] === null
            ? null : $after['undo'] - $before['undo'],
        'undo_indicator' => $postgres ? 'dead tuples in installation tables' : 'InnoDB history list length',
        'per_table' => $growth,
    ],
    'estimated' => [
        'basis' => 'measured bytes per LBT x 5,000,000 LBT per day, ordinary small creates only',
        'daily_bytes' => (int) round($bytesPerLbt * $daily),
        'monthly_bytes' => (int) round($bytesPerLbt * $daily * 30),
        'yearly_bytes_without_retention' => (int) round($bytesPerLbt * $daily * 365),
        'daily_log_bytes' => $walPerLbt === null ? null : (int) round($walPerLbt * $daily),
        'free_space_reserve_fraction' => 0.30,
        'provisioned_bytes_for_30_days_with_reserve' => (int) round($bytesPerLbt * $daily * 30 / 0.70),
        'temporary_bytes_for_largest_rebuild' => 2 * $largest,
        'assumptions' => [
            'Page allocation is coarse: InnoDB data_length and index_length move in 16 KiB pages and extents, so '
            . 'small samples over-state or under-state per-LBT bytes; use at least several hundred records.',
            'The workload is ordinary small creates on one fresh definition; document lines, updates, aged '
            . 'data, fan-out receipts and retained history are not in this figure.',
            'Retention bounds the ledgers (see docs/operations/retention.md); the yearly figure assumes none.',
            'Replica network amplification equals the log bytes per LBT for each replica.',
            'Backup size and restore time are measured by the backup drills, not by this tool.',
        ],
    ],
];
@mkdir($root . '/build/perf', 0775, true);
file_put_contents(
    $root . '/build/perf/storage-' . $engine . '.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
);
fwrite(STDOUT, json_encode($report['measured'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
fwrite(STDOUT, json_encode(array_diff_key($report['estimated'], ['assumptions' => 1]), JSON_PRETTY_PRINT) . "\n");
