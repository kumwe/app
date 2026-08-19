<?php

/**
 * The second database session a deadlock needs, run as a separate operating-system process.
 *
 * A lock-order deadlock cannot be produced from one PHP process: the cycle only exists while both
 * sessions are blocked at the same time, and a blocked session blocks the interpreter with it. This
 * script is therefore spawned by `BusinessRecordDeadlockIntegrationTest`, opens its own connection from
 * the same environment the test kernel uses, and plays the second half of the inversion — take the
 * second row, wait for the test to take the first, then reach for the first. The two processes
 * coordinate through files rather than sleeps wherever waiting has to be observable.
 *
 * Usage: php tests/Support/deadlock-partner.php <handshake-directory> <first-row-id> <second-row-id>
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$directory = $argv[1] ?? null;
$firstRow = $argv[2] ?? null;
$secondRow = $argv[3] ?? null;
if (!is_string($directory) || !is_string($firstRow) || !is_string($secondRow)) {
    fwrite(STDERR, "Usage: deadlock-partner.php <handshake-directory> <first-row-id> <second-row-id>\n");
    exit(2);
}

$await = static function (string $path, float $seconds): bool {
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        if (is_file($path)) {
            return true;
        }
        usleep(10_000);
    }

    return false;
};

$configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
$database = (new DoctrineConnectionFactory($configuration->database))->create();
$table = (new TableNames($database, $configuration->database->tablePrefix))->quoted('business_number_sequences');
$touch = sprintf('UPDATE %s SET current_value = current_value + 1 WHERE id = ?', $table);
$outcome = 'no-conflict';

try {
    $database->beginTransaction();
    $database->executeStatement($touch, [$secondRow], [Types::GUID]);
    file_put_contents($directory . '/partner-holds-second', 'held');
    if (!$await($directory . '/test-holds-first', 10.0)) {
        $outcome = 'handshake-timeout';
    } else {
        $database->executeStatement($touch, [$firstRow], [Types::GUID]);
    }
    $database->commit();
} catch (Throwable $exception) {
    $outcome = $exception::class;

    try {
        if ($database->isTransactionActive()) {
            $database->rollBack();
        }
    } catch (Throwable) {
        // The server may already have rolled this session back as the deadlock victim.
    }
}

file_put_contents($directory . '/partner-outcome', $outcome);
exit(0);
