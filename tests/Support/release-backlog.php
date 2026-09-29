<?php

/**
 * Seed a bounded aged backlog before fault drills, then drain it with four actual artifact workers.
 *
 * Invoke with seed|drain EVIDENCE_DIRECTORY. Worker children use worker DIRECTORY INDEX internally.
 * Availability timestamps are fixture aging; all claims, handlers, settlements and processes are real.
 *
 * @since  2.0.0-beta.1
 */

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Automation\Worker;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\ContainerFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\Automation\JobQueue;

require_once __DIR__ . '/release-qualification-bootstrap.php';

$mode = $argv[1] ?? '';
$directory = $argv[2] ?? '';
if (!in_array($mode, ['seed', 'drain', 'worker'], true) || !is_dir($directory)) {
    throw new RuntimeException('Use seed|drain EVIDENCE_DIRECTORY.');
}
$container = $mode === 'seed'
    ? TestKernelFactory::create(Environment::fromGlobals())
    : (new ContainerFactory())->create(Environment::fromGlobals());
$queue = $container->get(JobQueue::class);
$database = $container->get(Connection::class);
$tables = $container->get(TableNames::class);
if (!$queue instanceof JobQueue || !$database instanceof Connection || !$tables instanceof TableNames) {
    throw new RuntimeException('The artifact queue or database is unavailable.');
}
$recordPath = $directory . '/backlog.json';
if ($mode === 'seed') {
    $context = TestKernelFactory::administratorContext($container);
    $queueName = 'release-backlog-' . bin2hex(random_bytes(5));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    for ($index = 0; $index < 1000; $index++) {
        $id = $queue->enqueue($context, 'system.sessions.purge', [], $now, $queueName);
        $aged = $now->modify('-' . (1 + $index % 90) . ' days');
        $database->update($tables->raw('jobs'), ['available_at' => $aged, 'created_at' => $aged], ['id' => $id], [
            'available_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }
    file_put_contents($recordPath, json_encode([
        'queue' => $queueName,
        'jobs' => 1000,
        'age_days' => [1, 90],
        'age_method' => 'Fixture timestamps after enqueue through the production API.',
        'workers' => 4,
        'maximum_recovery_seconds' => 180,
        'complete' => false,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    exit(0);
}
$record = json_decode((string) file_get_contents($recordPath), true, 64, JSON_THROW_ON_ERROR);
$queueName = $record['queue'];
if ($mode === 'worker') {
    $index = $argv[3] ?? '';
    if (!preg_match('/^[0-3]$/D', $index)) {
        throw new RuntimeException('Unknown backlog worker slot.');
    }
    $worker = $container->get(Worker::class);
    if (!$worker instanceof Worker) {
        throw new RuntimeException('The production worker is unavailable.');
    }
    $context = TestKernelFactory::workerContext($container);
    $identity = 'release-backlog-worker-' . $index;
    file_put_contents($directory . '/ready-' . $index, (string) getmypid());
    $deadline = microtime(true) + 60;
    while (!is_file($directory . '/start')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('The worker start barrier timed out.');
        }
        clearstatcache();
        usleep(10_000);
    }
    $started = microtime(true);
    $handled = 0;
    try {
        while ($worker->runOnce($context, $queueName, $identity, 60, 60)) {
            $handled++;
            if (microtime(true) - $started > $record['maximum_recovery_seconds']) {
                throw new RuntimeException('The backlog recovery deadline was exceeded.');
            }
        }
    } finally {
        $queue->disconnect($context, $identity, $queueName);
    }
    file_put_contents($directory . '/worker-' . $index . '.json', json_encode([
        'pid' => getmypid(), 'handled' => $handled, 'started' => $started, 'finished' => microtime(true),
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    exit(0);
}
$processes = [];
try {
    for ($index = 0; $index < $record['workers']; $index++) {
        $process = proc_open(
            [PHP_BINARY, __FILE__, 'worker', $directory, (string) $index],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/worker-' . $index . '.log', 'w'],
                2 => ['file', $directory . '/worker-' . $index . '.error.log', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('A backlog worker could not start.');
        }
        $processes[] = $process;
    }
    $deadline = microtime(true) + 60;
    while (count(glob($directory . '/ready-*') ?: []) !== $record['workers']) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Not every real worker reached the barrier.');
        }
        usleep(10_000);
    }
    $started = microtime(true);
    file_put_contents($directory . '/start', 'drain');
    foreach ($processes as $index => $process) {
        if (proc_close($process) !== 0) {
            unset($processes[$index]);
            throw new RuntimeException('A backlog worker failed; inspect its retained error log.');
        }
        unset($processes[$index]);
    }
    $elapsed = microtime(true) - $started;
    $workers = [];
    for ($index = 0; $index < $record['workers']; $index++) {
        $workers[] = json_decode(
            (string) file_get_contents($directory . '/worker-' . $index . '.json'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
    }
    $completed = (int) $database->fetchOne(sprintf(
        "SELECT COUNT(*) FROM %s WHERE queue = ? AND status = 'completed' AND attempts = 1 "
            . 'AND lease_owner IS NULL AND lease_token IS NULL',
        $tables->quoted('jobs'),
    ), [$queueName]);
    $total = (int) $database->fetchOne(sprintf(
        'SELECT COUNT(*) FROM %s WHERE queue = ?',
        $tables->quoted('jobs'),
    ), [$queueName]);
    $overlap = min(array_column($workers, 'finished')) - max(array_column($workers, 'started'));
    $passed = $completed === $record['jobs'] && $total === $record['jobs']
        && array_sum(array_column($workers, 'handled')) === $record['jobs']
        && min(array_column($workers, 'handled')) > 0 && $overlap > 0
        && $elapsed <= $record['maximum_recovery_seconds'];
    $record['complete'] = true;
    $record['passed'] = $passed;
    $record['elapsed_seconds'] = $elapsed;
    $record['four_worker_overlap_seconds'] = $overlap;
    $record['completed_once'] = $completed;
    $record['worker_observations'] = $workers;
    file_put_contents($recordPath, json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    if (!$passed) {
        throw new RuntimeException('Backlog recovery violated its count, fence, overlap or recovery-time invariant.');
    }
    echo "Drained {$completed} aged jobs with four workers in {$elapsed} seconds.\n";
} finally {
    foreach ($processes as $process) {
        if (is_resource($process)) {
            proc_terminate($process, SIGKILL);
            proc_close($process);
        }
    }
}
