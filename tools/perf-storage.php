<?php

/**
 * Measure storage and write amplification per logical transaction and forecast growth (P5-I).
 *
 * Usage: php tools/perf-storage.php [--records=500] [--workload=create|update|document|aged] [--lines=10]
 *        [--dataset-records=2000] [--dataset-seed=20260924] [--backup]
 *
 * Runs the requested number of logical business transactions of one workload through the production
 * `BusinessRecordService` on the configured disposable test database: `create` makes ordinary records,
 * `update` rewrites records created (unmeasured) beforehand, `document` commits a header with `--lines`
 * owned lines per LBT, and `aged` makes ordinary records on a table first seeded with the declared aged
 * dataset (tools/PerfDataset.php). `--backup` also takes a logical dump of the installation's tables before
 * and after the measured phase with the engine's own dump tool and reports the dump bytes per LBT and the
 * dump throughput. It measures, per LBT:
 * physical row mutations (MariaDB session `Handler_write/update/delete`, PostgreSQL `pg_stat_database`
 * tuple counters after a forced statistics flush), table and index bytes by table, write-ahead log bytes
 * (PostgreSQL LSN distance, MariaDB binary log position), and undo pressure (InnoDB history list length,
 * PostgreSQL dead tuples). It then publishes the daily, monthly and yearly forecast at the five-million
 * LBT enterprise target, the 30% free-space reserve and the temporary space for the largest rebuild.
 * Requires APP_ENV=testing. Writes build/perf/storage-<engine>-<workload>.json.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\UpdateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\App\Tools\Performance\PerfDataset;
use Ramsey\Uuid\Uuid;

use function Kumwe\App\Tools\Performance\seedAgedRecords;

require_once __DIR__ . '/PerfDataset.php';

if (getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "The storage benchmark requires APP_ENV=testing and a disposable test database.\n");
    exit(2);
}
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$records = 500;
$workload = 'create';
$lines = 10;
$datasetRecords = 2_000;
$datasetSeed = PerfDataset::DEFAULT_SEED;
$backup = false;
$usage = "Usage: php tools/perf-storage.php [--records=N>=10] [--workload=create|update|document|aged] "
    . "[--lines=1..1000] [--dataset-records=0..200000] [--dataset-seed=N] [--backup]\n";
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--backup') {
        $backup = true;
    } elseif (preg_match('/^--records=(\d+)$/D', $argument, $match) === 1 && (int) $match[1] >= 10) {
        $records = (int) $match[1];
    } elseif (preg_match('/^--workload=(create|update|document|aged)$/D', $argument, $match) === 1) {
        $workload = $match[1];
    } elseif (
        preg_match('/^--lines=(\d+)$/D', $argument, $match) === 1 && (int) $match[1] >= 1 && (int) $match[1] <= 1000
    ) {
        $lines = (int) $match[1];
    } elseif (preg_match('/^--dataset-records=(\d+)$/D', $argument, $match) === 1 && (int) $match[1] <= 200_000) {
        $datasetRecords = (int) $match[1];
    } elseif (preg_match('/^--dataset-seed=(\d+)$/D', $argument, $match) === 1) {
        $datasetSeed = (int) $match[1];
    } else {
        fwrite(STDERR, $usage);
        exit(2);
    }
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
$engine = $postgres ? 'pgsql' : ($database->getDatabasePlatform() instanceof MariaDBPlatform ? 'mariadb' : 'mysql');
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

/**
 * Take a logical dump of the installation's tables with the engine's own tool and report its size and time.
 *
 * The password travels in the child's environment, never on its command line.
 *
 * @param   Connection  $database  Connection whose parameters name the server and database.
 * @param   bool        $postgres  Whether the engine is PostgreSQL.
 * @param   string      $prefix    Installation table prefix.
 *
 * @return  ?array{bytes: int, seconds: float, tool: string}  The dump size and duration, or null when no
 *          dump tool is installed or the dump failed.
 *
 * @since   2.0.0
 */
function storageDump(Connection $database, bool $postgres, string $prefix): ?array
{
    $parameters = $database->getParams();
    $tool = null;
    foreach ($postgres ? ['pg_dump'] : ['mariadb-dump', 'mysqldump'] as $candidate) {
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
        if ($path !== '') {
            $tool = $path;
            break;
        }
    }
    if ($tool === null) {
        return null;
    }
    $host = (string) ($parameters['host'] ?? '127.0.0.1');
    $port = (string) ($parameters['port'] ?? ($postgres ? 5432 : 3306));
    $user = (string) ($parameters['user'] ?? '');
    $name = (string) ($parameters['dbname'] ?? '');
    $environment = getenv();
    if ($postgres) {
        $environment['PGPASSWORD'] = (string) ($parameters['password'] ?? '');
        $command = [$tool, '-h', $host, '-p', $port, '-U', $user, '-d', $name, '--data-only', '-t', $prefix . '*'];
    } else {
        $environment['MYSQL_PWD'] = (string) ($parameters['password'] ?? '');
        $tables = $database->fetchFirstColumn(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?',
            [$prefix . '%'],
        );
        $command = [$tool, '-h', $host, '-P', $port, '-u', $user, '--single-transaction', '--no-create-info',
            '--skip-triggers', $name, ...array_map('strval', $tables)];
    }
    $started = hrtime(true);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        return null;
    }
    $bytes = 0;
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 1 << 20);
        $bytes += $chunk === false ? 0 : strlen($chunk);
    }
    fclose($pipes[1]);
    $status = proc_close($process);

    return $status === 0
        ? ['bytes' => $bytes, 'seconds' => (hrtime(true) - $started) / 1_000_000_000, 'tool' => basename($tool)]
        : null;
}

$suffix = 'storage' . bin2hex(random_bytes(3));
$dataset = new PerfDataset($datasetSeed, $workload === 'aged' ? $datasetRecords : 0);
if ($workload === 'document') {
    $lineDocument = NeutralBusinessFixture::documentLineDocument(
        NeutralBusinessFixture::DOCUMENT_SUFFIX,
        Uuid::uuid7()->toString(),
    );
    NeutralBusinessFixture::install($container, $context, $lineDocument);
    $definition = NeutralBusinessFixture::install($container, $context, NeutralBusinessFixture::documentHeaderDocument(
        NeutralBusinessFixture::DOCUMENT_SUFFIX,
        Uuid::uuid7()->toString(),
        (string) $lineDocument['handle'],
    ));
} else {
    $definition = NeutralBusinessFixture::install(
        $container,
        $context,
        NeutralBusinessFixture::document($suffix, Uuid::uuid7()->toString()),
    );
}
$create = static function (string $label) use ($service, $context, $definition) {
    return $service->create(new CreateRecordCommand(
        $context,
        $definition->handle,
        NeutralBusinessFixture::recordValues($label),
        NeutralBusinessFixture::idempotencyKey('storage-' . bin2hex(random_bytes(8))),
        recordId: Uuid::uuid7()->toString(),
    ));
};
$prepared = [];
$seeding = null;
if ($workload === 'update') {
    for ($index = 0; $index < $records; $index++) {
        $prepared[] = $create('Storage update base ' . $index)->recordId;
    }
}
if ($workload === 'aged' && $dataset->records > 0) {
    $installations = $container->get(BusinessSchemaInstallationRepository::class);
    $table = $installations instanceof BusinessSchemaInstallationRepository
        ? $installations->find($definition->id)?->blueprint->table('record')
        : null;
    if ($table === null) {
        throw new RuntimeException('The aged storage fixture has no record table.');
    }
    $seeding = seedAgedRecords(
        static fn (int $index): string => $create('Storage aged ' . $index)->recordKey,
        $database,
        $table,
        $dataset,
        new DateTimeImmutable('now', new DateTimeZone('UTC')),
    );
}
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
$dumpBefore = $backup ? storageDump($database, $postgres, $prefix) : null;
$before = storageSnapshot($database, $postgres, $prefix);
$started = hrtime(true);
for ($index = 0; $index < $records; $index++) {
    if ($workload === 'update') {
        $values = NeutralBusinessFixture::recordValues('Storage update ' . $index);
        $service->update(new UpdateRecordCommand(
            $context,
            $definition->handle,
            $prepared[$index],
            1,
            ['name' => $values['name'] . ' revised', 'amount' => '1.000000000000000000000000000000'],
            NeutralBusinessFixture::idempotencyKey('storage-update-' . bin2hex(random_bytes(8))),
        ));
        continue;
    }
    if ($workload === 'document') {
        $documentLines = [];
        for ($line = 1; $line <= $lines; $line++) {
            $documentLines[] = new DocumentLineInput([
                'code' => 'line-' . $suffix . '-' . $index . '-' . $line,
                'description' => 'Storage line ' . $line,
                'amount' => '1.00',
            ]);
        }
        $service->writeDocument(new WriteDocumentCommand(
            $context,
            $definition->handle,
            'lines',
            ['title' => 'Storage document ' . $index, 'total' => $lines . '.00'],
            $documentLines,
            NeutralBusinessFixture::idempotencyKey('storage-document-' . bin2hex(random_bytes(8))),
            recordId: Uuid::uuid7()->toString(),
        ));
        continue;
    }
    $create('Storage sample ' . $index);
}
$elapsed = (hrtime(true) - $started) / 1_000_000_000;
$analyse();
$after = storageSnapshot($database, $postgres, $prefix);
$dumpAfter = $backup ? storageDump($database, $postgres, $prefix) : null;

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
$dumpDelta = $dumpBefore !== null && $dumpAfter !== null ? $dumpAfter['bytes'] - $dumpBefore['bytes'] : null;
$report = [
    'tool' => 'kumwe-storage-forecast',
    'engine' => $engine,
    'workload' => $workload,
    'lines_per_lbt' => $workload === 'document' ? $lines : null,
    'database_version' => (string) $database->fetchOne('SELECT VERSION()'),
    'measured_at' => gmdate('c'),
    'records' => $records,
    'elapsed_seconds' => round($elapsed, 2),
    'dataset' => $workload === 'aged' ? $dataset->describe() + ['seeding' => $seeding] : null,
    'backup' => !$backup ? null : [
        'dump_tool' => $dumpAfter['tool'] ?? null,
        'dump_bytes_before' => $dumpBefore['bytes'] ?? null,
        'dump_bytes_after' => $dumpAfter['bytes'] ?? null,
        'dump_bytes_per_lbt' => $dumpDelta === null ? null : round($dumpDelta / $records, 1),
        'dump_seconds_after' => $dumpAfter === null ? null : round($dumpAfter['seconds'], 3),
        'dump_bytes_per_second' => $dumpAfter === null || $dumpAfter['seconds'] <= 0.0
            ? null : (int) round($dumpAfter['bytes'] / $dumpAfter['seconds']),
        'scope' => 'logical data-only dump of every installation table on this test database',
    ],
    'measured' => [
        'physical_row_mutations_per_lbt' => round(($after['mutations'] - $before['mutations']) / $records, 2),
        'physical_row_mutations_source' => $postgres
            ? 'pg_stat_database tup_inserted+tup_updated+tup_deleted (database-wide, forced flush)'
            : 'session Handler_write+Handler_update+Handler_delete (includes internal temporary tables)',
        'table_bytes_per_lbt' => round($dataBytes / $records, 1),
        'index_bytes_per_lbt' => round($indexBytes / $records, 1),
        'physical_row_mutations_per_line' => $workload === 'document'
            ? round(($after['mutations'] - $before['mutations']) / ($records * $lines), 2) : null,
        'bytes_per_line' => $workload === 'document'
            ? round(($dataBytes + $indexBytes) / ($records * $lines), 1) : null,
        'log_bytes_per_lbt' => $walPerLbt === null ? null : round($walPerLbt, 1),
        'log_kind' => $postgres ? 'write-ahead log (LSN distance)' : 'binary log position distance',
        'undo_indicator_delta' => $after['undo'] === null || $before['undo'] === null
            ? null : $after['undo'] - $before['undo'],
        'undo_indicator' => $postgres ? 'dead tuples in installation tables' : 'InnoDB history list length',
        'per_table' => $growth,
    ],
    'estimated' => [
        'basis' => sprintf('measured bytes per LBT x 5,000,000 LBT per day, %s workload only', $workload),
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
            'The workload is one kind of LBT on one definition; a mixed envelope weights the per-workload figures '
            . 'by its declared mix, and fan-out receipts and retained history are not in this figure.',
            'Retention bounds the ledgers (see docs/operations/retention.md); the yearly figure assumes none.',
            'Replica network amplification equals the log bytes per LBT for each replica: MySQL-family replicas '
            . 'receive the binary log and PostgreSQL streaming replicas the write-ahead log, byte for byte.',
            'The logical dump delta is the backup growth per LBT for a logical backup; physical backups copy '
            . 'table and index bytes. Restore time is measured by the backup drills, not by this tool.',
        ],
    ],
];
@mkdir($root . '/build/perf', 0775, true);
file_put_contents(
    $root . '/build/perf/storage-' . $engine . '-' . $workload . '.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
);
fwrite(STDOUT, json_encode(
    ['workload' => $workload, 'backup' => $report['backup']] + $report['measured'],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
) . "\n");
fwrite(STDOUT, json_encode(array_diff_key($report['estimated'], ['assumptions' => 1]), JSON_PRETTY_PRINT) . "\n");
