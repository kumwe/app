<?php

/**
 * One independent queue-permit claimant, run as its own operating-system process for an over-claim drill.
 *
 * A declared queue ceiling is only meaningful under genuine contention: several workers on distinct
 * database sessions racing for the same permit rows, each committing its claim before the others see
 * it. This script is one such worker. In `loop` mode it repeatedly acquires a permit through the
 * production `DoctrineQueuePermits`, records which slot it was granted and the monotonic instants at
 * which its hold began and ended, releases it, and keeps going until it has been granted the requested
 * number of permits. The drill then proves over-claim never happened by checking that no slot was ever
 * held by two claimants at once and that the number of simultaneously held slots never exceeded the
 * ceiling. In `hold` mode it acquires one permit, commits, reports, and blocks until it is killed, so
 * the drill can measure how long a lost worker's capacity stays dead.
 *
 * Usage: php tests/Support/permit-claimant.php <mode> <handshake-directory> <table-prefix> <queue>
 *        <maximum-in-flight> <label> <target-acquisitions>
 *
 * Modes: `loop`, `hold`.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\Infrastructure\Automation\DoctrineQueuePermits;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\Automation\QueueRuntimePolicy;
use Ramsey\Uuid\Uuid;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$mode = $argv[1] ?? null;
$directory = $argv[2] ?? null;
$prefix = $argv[3] ?? null;
$queue = $argv[4] ?? null;
$maximum = (int) ($argv[5] ?? 0);
$label = $argv[6] ?? null;
$target = (int) ($argv[7] ?? 0);
if (
    !in_array($mode, ['loop', 'hold'], true) || !is_string($directory) || !is_string($prefix)
    || !is_string($queue) || $maximum < 1 || !is_string($label) || $target < 1
) {
    fwrite(STDERR, "Usage: permit-claimant.php <mode> <dir> <prefix> <queue> <maximum> <label> <target>\n");
    exit(2);
}

$await = static function (string $path, float $seconds): bool {
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        clearstatcache(true, $path);
        if (is_file($path)) {
            return true;
        }
        usleep(2_000);
    }

    return false;
};
$mark = static function (string $name, string $content = 'done') use ($directory): void {
    file_put_contents($directory . '/' . $name . '.tmp', $content);
    rename($directory . '/' . $name . '.tmp', $directory . '/' . $name);
};

$configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
$database = (new DoctrineConnectionFactory($configuration->database))->create();
$tables = new TableNames($database, $prefix);
$permits = new DoctrineQueuePermits($database, $tables);
$policy = new QueueRuntimePolicy($queue, 5, 5, $maximum, 7, 9);
$outcome = 'completed';
$holds = [];
$acquired = 0;
$exhausted = 0;

try {
    if (!$await($directory . '/start', 30.0)) {
        throw new RuntimeException('handshake-timeout:start');
    }
    $deadline = microtime(true) + 60.0;
    while ($acquired < $target && microtime(true) < $deadline) {
        $token = Uuid::uuid7()->toString();
        $now = new DateTimeImmutable();
        $database->beginTransaction();
        $granted = $permits->acquire(
            $policy,
            $now,
            'job',
            Uuid::uuid7()->toString(),
            '',
            $token,
            $now->modify('+5 seconds'),
        );
        $database->commit();
        $begin = hrtime(true);
        if (!$granted) {
            $exhausted++;
            usleep(500);
            continue;
        }
        $slotValue = $database->fetchOne(sprintf(
            'SELECT slot_number FROM %s WHERE queue_id = ? AND lease_token = ?',
            $tables->quoted('job_queue_permits'),
        ), [$queue, $token]);
        if (!is_int($slotValue) && !is_string($slotValue)) {
            throw new RuntimeException('The granted permit could not be located by its token.');
        }
        $slot = (int) $slotValue;
        $acquired++;
        if ($mode === 'hold') {
            $mark('held-' . $label, $slot . ':' . $token);
            // Hold the permit until the drill kills this process; the polled marker is never written.
            while (!is_file($directory . '/never-written')) {
                sleep(1);
            }
        }
        usleep(random_int(200, 2_000));
        $end = hrtime(true);
        $database->beginTransaction();
        $permits->release($queue, $token);
        $database->commit();
        $holds[] = ['slot' => $slot, 'begin_ns' => $begin, 'end_ns' => $end];
    }
    if ($acquired < $target) {
        $outcome = 'target-not-reached';
    }
} catch (Throwable $exception) {
    $outcome = $exception::class . ':' . $exception->getMessage();
    try {
        if ($database->isTransactionActive()) {
            $database->rollBack();
        }
    } catch (Throwable) {
        // The server may already have ended this session; the outcome file carries the first failure.
    }
}

$mark('claimant-' . $label . '.json', json_encode([
    'outcome' => $outcome,
    'acquired' => $acquired,
    'exhausted' => $exhausted,
    'holds' => $holds,
], JSON_THROW_ON_ERROR));
exit($outcome === 'completed' ? 0 : 1);
