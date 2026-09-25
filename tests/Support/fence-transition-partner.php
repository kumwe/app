<?php

/**
 * The second database session a generation-fence drill needs, run as a separate operating-system process.
 *
 * A schema transition that *waits* for an in-flight writer cannot be observed from one PHP process: the
 * blocked statement blocks the interpreter with it, so nothing is left to notice that the writer's commit
 * is what released it. This script plays whichever side of the fence the drill needs. As the transition
 * it takes the exclusive installation lock the schema executor takes, reports when the lock request was
 * issued and when it was granted, and holds the transition open until told to commit. As a writer it takes
 * the production shared fence through `DoctrineBusinessRecordMutationFence::lock()` and then either rolls
 * back on request or blocks until the drill kills it, which is how the drill proves that rollback and a
 * crash both release the authority the fence granted.
 *
 * Usage: php tests/Support/fence-transition-partner.php <mode> <handshake-directory> <definition-id> <handle>
 *
 * Modes: `transition`, `writer-rollback`, `writer-crash`.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordMutationFence;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\Context\Contract\SystemActor;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$mode = $argv[1] ?? null;
$directory = $argv[2] ?? null;
$definitionId = $argv[3] ?? null;
$handle = $argv[4] ?? null;
if (
    !in_array($mode, ['transition', 'writer-rollback', 'writer-crash'], true)
    || !is_string($directory) || !is_string($definitionId) || !is_string($handle)
) {
    fwrite(STDERR, "Usage: fence-transition-partner.php <mode> <handshake-directory> <definition-id> <handle>\n");
    exit(2);
}

$await = static function (string $path, float $seconds): bool {
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        clearstatcache(true, $path);
        if (is_file($path)) {
            return true;
        }
        usleep(5_000);
    }

    return false;
};
$mark = static function (string $name, string $content = 'done') use ($directory): void {
    file_put_contents($directory . '/' . $name . '.tmp', $content);
    rename($directory . '/' . $name . '.tmp', $directory . '/' . $name);
};

$configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
$database = (new DoctrineConnectionFactory($configuration->database))->create();
$tables = new TableNames($database, $configuration->database->tablePrefix);
$outcome = 'completed';

try {
    if ($mode === 'transition') {
        if (!$await($directory . '/writer-holds', 30.0)) {
            throw new RuntimeException('handshake-timeout:writer-holds');
        }
        $database->beginTransaction();
        $requested = hrtime(true);
        $mark('transition-requested', (string) $requested);
        // The same exclusive row update the schema executor issues when it moves an installation.
        $database->executeStatement(sprintf(
            "UPDATE %s SET status = 'installing' WHERE definition_id = ?",
            $tables->quoted('business_schema_installations'),
        ), [$definitionId]);
        $mark('transition-acquired', (string) ((hrtime(true) - $requested) / 1_000_000));
        if (!$await($directory . '/release-transition', 30.0)) {
            throw new RuntimeException('handshake-timeout:release-transition');
        }
        $database->commit();
        $mark('transition-committed');
    } else {
        $actor = new class implements SystemActor {
            /**
             * Name the drill's system actor; the fence reads only the site off the context.
             *
             * @return  string  Stable drill identity.
             *
             * @since   2.0.0
             */
            public function identifier(): string
            {
                return 'system:fence-transition-partner';
            }
        };
        $context = ExecutionContext::issueSystem(new stdClass(), $actor, SiteContext::default(), 'fence-partner');
        $fence = new DoctrineBusinessRecordMutationFence($database, $tables);
        $database->beginTransaction();
        $generation = $fence->lock($context, $handle);
        $mark('writer-holds', (string) $generation->definitionVersion);
        if ($mode === 'writer-crash') {
            // Hold the shared fence until the drill kills this process; the polled marker is never written.
            while (!is_file($directory . '/never-written')) {
                sleep(1);
            }
        }
        if (!$await($directory . '/rollback', 30.0)) {
            throw new RuntimeException('handshake-timeout:rollback');
        }
        $database->rollBack();
        $mark('writer-released');
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

$mark('partner-outcome', $outcome);
exit($outcome === 'completed' ? 0 : 1);
