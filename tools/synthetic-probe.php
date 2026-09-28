<?php

/**
 * Probe a running Kumwe deployment from the outside and write the result for Prometheus.
 *
 * Read-only and idempotent, so it is safe to run every minute against production from a host outside the
 * deployment. It checks liveness, readiness, the public page (including the echo of the probe's request
 * identifier and W3C trace context), the metrics endpoint's credential handling and, with a read token, one
 * authenticated REST read. Each check prints one JSON line; with `--textfile` the result is also written
 * atomically as a Prometheus textfile (`kumwe_probe_success`, `kumwe_probe_duration_seconds`,
 * `kumwe_probe_last_run_timestamp_seconds`) for node_exporter's textfile collector. Tokens are read from files,
 * never from arguments, so they do not appear in the process list.
 *
 * Usage:
 *   php tools/synthetic-probe.php --base-url=https://site.example [--textfile=/var/lib/node_exporter/kumwe.prom]
 *       [--metrics-token-file=PATH] [--api-token-file=PATH] [--site=default] [--page=/] [--timeout=10]
 *
 * Exit status: 0 when every check passed, 1 when any failed, 64 for a usage error.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\Tools\Observability\SyntheticProbe;

require_once __DIR__ . '/Observability/bootstrap.php';

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(base-url|textfile|metrics-token-file|api-token-file|site|page|timeout)=(.+)$/D', $argument, $match) !== 1) {
        fwrite(STDERR, "Usage: php tools/synthetic-probe.php --base-url=URL [--textfile=PATH] [--metrics-token-file=PATH]"
            . " [--api-token-file=PATH] [--site=SITE] [--page=PATH] [--timeout=SECONDS]\n");
        exit(64);
    }
    $options[$match[1]] = $match[2];
}
$baseUrl = $options['base-url'] ?? '';
if (preg_match('#^https?://[^\s/]+#', $baseUrl) !== 1) {
    fwrite(STDERR, "The probe needs --base-url=http(s)://host.\n");
    exit(64);
}
$token = static function (?string $path): ?string {
    if ($path === null) {
        return null;
    }
    $value = @file_get_contents($path);
    if (!is_string($value) || trim($value) === '') {
        fwrite(STDERR, sprintf("The token file %s is unreadable or empty.\n", $path));
        exit(64);
    }

    return trim($value);
};
$timeout = (float) ($options['timeout'] ?? '10');
$probe = new SyntheticProbe(
    $baseUrl,
    SyntheticProbe::streamTransport($timeout > 0 ? $timeout : 10.0),
    $token($options['metrics-token-file'] ?? null),
    $token($options['api-token-file'] ?? null),
    $options['site'] ?? 'default',
    $options['page'] ?? '/',
);
$results = $probe->run();
foreach ($results as $result) {
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
}
if (isset($options['textfile'])) {
    $staging = $options['textfile'] . '.' . getmypid() . '.tmp';
    if (@file_put_contents($staging, SyntheticProbe::exposition($results, time())) === false || !@rename($staging, $options['textfile'])) {
        @unlink($staging);
        fwrite(STDERR, sprintf("The probe result could not be written to %s.\n", $options['textfile']));
        exit(1);
    }
}
foreach ($results as $result) {
    if (!$result['success']) {
        exit(1);
    }
}
exit(0);
