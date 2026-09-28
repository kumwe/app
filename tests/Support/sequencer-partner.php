<?php

/**
 * A projection sequencer in its own operating-system process, so a drill can crash it or race it.
 *
 * The committed-source sequencer publishes a journal range in one short transaction. Whether a crash
 * in the middle of that transaction can lose or duplicate an event is only provable by actually
 * crashing one: this script runs the production `DoctrineProjectionEventSequencer` on its own database
 * session and, in the crash mode, wraps it in a transaction manager that performs every statement but
 * never commits, then blocks until the drill sends `SIGKILL`. The head-holding mode locks the singleton
 * head row and blocks, which is what a sequencer that died before allocating a range looks like to its
 * siblings. The race mode drains the staging table competitively against other copies of itself and
 * reports how many rows it published, so the drill can prove that concurrent sequencers neither skip nor
 * duplicate a committed source row.
 *
 * Usage: php tests/Support/sequencer-partner.php <mode> <handshake-directory> <label> [<batch-size>]
 *
 * Modes: `hold-head`, `crash-after-allocation`, `race`.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Kumwe\App\BusinessReporting\Infrastructure\DoctrineProjectionEventSequencer;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\Transaction\Contract\TransactionManager;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$mode = $argv[1] ?? null;
$directory = $argv[2] ?? null;
$label = $argv[3] ?? null;
$batch = (int) ($argv[4] ?? 7);
if (
    !in_array($mode, ['hold-head', 'crash-after-allocation', 'race'], true)
    || !is_string($directory) || !is_string($label) || $batch < 1 || $batch > 1_000
) {
    fwrite(STDERR, "Usage: sequencer-partner.php <mode> <handshake-directory> <label> [<batch-size>]\n");
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
$block = static function (string $directory): void {
    // Hold the session until the drill kills this process; the polled marker is never written.
    while (!is_file($directory . '/never-written')) {
        sleep(1);
    }
};

$configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
$database = (new DoctrineConnectionFactory($configuration->database))->create();
$tables = new TableNames($database, $configuration->database->tablePrefix);
$outcome = 'completed';

try {
    if ($mode === 'hold-head') {
        $database->beginTransaction();
        $database->fetchOne(sprintf(
            'SELECT last_sequence FROM %s WHERE singleton_id = 1 FOR UPDATE',
            $tables->quoted('business_projection_event_head'),
        ));
        $mark('head-held-' . $label);
        $block($directory);
    }
    if ($mode === 'crash-after-allocation') {
        $manager = new class ($database, $mark, $directory) implements TransactionManager {
            /**
             * Bind the never-committing manager to the session and the drill's marker writer.
             *
             * @param  Connection  $connection  Session whose transaction is left open.
             * @param  Closure     $mark        Writes a handshake marker from a name and content.
             * @param  string      $directory   Handshake directory the block condition polls.
             *
             * @since  2.0.0
             */
            public function __construct(
                private readonly Connection $connection,
                private readonly Closure $mark,
                private readonly string $directory,
            ) {
            }

            /**
             * Run the operation inside a transaction that is never committed, then block for the kill.
             *
             * @param   callable(): mixed  $operation  The sequencer's whole publication transaction.
             *
             * @return  mixed  Only reached if the drill never kills the process, which it always does.
             *
             * @since   2.0.0
             */
            public function transactional(callable $operation): mixed
            {
                $this->connection->beginTransaction();
                $result = $operation();
                ($this->mark)('allocated', is_int($result) ? (string) $result : 'unexpected');
                while (!is_file($this->directory . '/never-written')) {
                    sleep(1);
                }

                return $result;
            }

            /**
             * Ignore completion hooks; the drill never reaches a commit.
             *
             * @param   callable(): void  $operation  Ignored.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function afterCommit(callable $operation): void
            {
            }

            /**
             * Ignore rollback hooks; the server rolls the session back when the process dies.
             *
             * @param   callable(): void  $operation  Ignored.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function afterRollback(callable $operation): void
            {
            }
        };
        (new DoctrineProjectionEventSequencer($database, $tables, $manager))->sequence($batch);
    }
    if ($mode === 'race') {
        $sequencer = new DoctrineProjectionEventSequencer(
            $database,
            $tables,
            new DoctrineTransactionManager($database),
        );
        if (!$await($directory . '/start', 30.0)) {
            throw new RuntimeException('handshake-timeout:start');
        }
        $total = 0;
        $rounds = 0;
        $idle = 0;
        $deadline = microtime(true) + 60.0;
        while (microtime(true) < $deadline) {
            $published = $sequencer->sequence($batch);
            $rounds++;
            $total += $published;
            if ($published > 0) {
                $idle = 0;
                continue;
            }
            $remaining = $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM (SELECT staging_sequence FROM %s LIMIT 1) probe',
                $tables->quoted('business_projection_event_staging'),
            ));
            if ((is_int($remaining) || is_string($remaining)) && (int) $remaining === 0 && ++$idle >= 5) {
                break;
            }
            usleep(1_000);
        }
        $mark(
            'race-' . $label . '.json',
            json_encode(['published' => $total, 'rounds' => $rounds], JSON_THROW_ON_ERROR),
        );
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

$mark('outcome-' . $label, $outcome);
exit($outcome === 'completed' ? 0 : 1);
