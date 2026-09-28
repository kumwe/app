<?php

/**
 * One independent export completion charging a site's byte budget, run as its own process for a race drill.
 *
 * Each claimant opens its own session, waits for the shared start marker, and charges the given bytes
 * through the production `DoctrineExportSiteByteBudget` inside a transaction that it holds open briefly,
 * as a completion transaction would. It writes `charged` or `exhausted` to its outcome marker. The drill
 * then proves that the site's total never passed the budget however the claimants interleaved, including
 * the race to create the site's first row.
 *
 * Usage: php tests/Support/export-budget-claimant.php <handshake-directory> <table-prefix> <site>
 *        <bytes-per-day> <bytes> <label>
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\BusinessReporting\Application\ExportSiteByteBudgetExhausted;
use Kumwe\App\BusinessReporting\Infrastructure\DoctrineExportSiteByteBudget;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$directory = $argv[1] ?? null;
$prefix = $argv[2] ?? null;
$site = $argv[3] ?? null;
$budget = (int) ($argv[4] ?? 0);
$bytes = (int) ($argv[5] ?? 0);
$label = $argv[6] ?? null;
if (
    !is_string($directory) || !is_string($prefix) || !is_string($site) || $budget < 1 || $bytes < 1
    || !is_string($label)
) {
    fwrite(STDERR, "Usage: export-budget-claimant.php <dir> <prefix> <site> <bytes-per-day> <bytes> <label>\n");
    exit(2);
}

$deadline = microtime(true) + 30.0;
while (!is_file($directory . '/start')) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "handshake-timeout:start\n");
        exit(3);
    }
    usleep(2_000);
    clearstatcache(true, $directory . '/start');
}

$configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
$database = (new DoctrineConnectionFactory($configuration->database))->create();
$charger = new DoctrineExportSiteByteBudget($database, new TableNames($database, $prefix), $budget);
$outcome = 'charged';
try {
    $database->transactional(static function () use ($charger, $site, $bytes): void {
        $charger->charge($site, $bytes, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        usleep(50_000);
    });
} catch (ExportSiteByteBudgetExhausted) {
    $outcome = 'exhausted';
} catch (Throwable $failure) {
    $outcome = 'error:' . $failure::class . ':' . $failure->getMessage();
} finally {
    $database->close();
}
file_put_contents($directory . '/' . $label . '.tmp', $outcome);
rename($directory . '/' . $label . '.tmp', $directory . '/' . $label);
