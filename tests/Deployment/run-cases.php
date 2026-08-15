<?php

/**
 * Run every regression case the deployed-artifact lane declares, inside the deployed artifact.
 *
 * The lane's own failure mode is the one it exists to prevent: a case that is declared, wired into a
 * workflow and never executed where it matters. This driver therefore refuses to report success unless every
 * case in the manifest produced a result of its own. Each case is a separate process, so a fatal error in
 * one is a failure of that case rather than of the run, and each reports a single JSON object on its last
 * line.
 *
 * Usage:
 *   php tests/Deployment/run-cases.php [--manifest=PATH] [--report=PATH] [--memory-limit=256M]
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\CMS\Tests\Deployment\CaseReport;

require __DIR__ . '/CaseReport.php';

$root = dirname(__DIR__, 2);
$manifestPath = __DIR__ . '/cases.json';
$reportPath = null;
$memoryLimit = '256M';

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--manifest=')) {
        $manifestPath = substr($argument, strlen('--manifest='));
        continue;
    }
    if (str_starts_with($argument, '--report=')) {
        $reportPath = substr($argument, strlen('--report='));
        continue;
    }
    if (str_starts_with($argument, '--memory-limit=')) {
        $memoryLimit = substr($argument, strlen('--memory-limit='));
        continue;
    }

    fwrite(STDERR, sprintf("Unknown argument %s.\n", $argument));
    exit(1);
}

$raw = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
/** @var mixed $manifest */
$manifest = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($manifest) || !is_array($manifest['cases'] ?? null) || $manifest['cases'] === []) {
    fwrite(STDERR, sprintf("The deployed-artifact case manifest at %s declares no cases.\n", $manifestPath));
    exit(1);
}

$results = [];
$failures = [];

foreach ($manifest['cases'] as $case) {
    if (!is_array($case) || !is_string($case['id'] ?? null) || !is_string($case['script'] ?? null)) {
        $failures[] = 'A case entry has no identifier or no script.';
        continue;
    }
    $id = $case['id'];
    $script = $case['script'];
    $path = $root . '/' . $script;
    if (!is_file($path)) {
        $failures[] = sprintf('Case %s names %s, which is not in the artifact.', $id, $script);
        $results[] = ['case' => $id, 'status' => 'missing', 'detail' => $script];
        continue;
    }

    $lines = [];
    $status = 0;
    exec(
        sprintf(
            '%s -d memory_limit=%s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($memoryLimit),
            escapeshellarg($path),
        ),
        $lines,
        $status,
    );
    $output = implode("\n", $lines);

    /** @var mixed $reported */
    $reported = $lines === [] ? null : json_decode((string) end($lines), true);
    if (!is_array($reported) || ($reported['case'] ?? null) !== $id) {
        $failures[] = sprintf(
            "Case %s produced no result of its own. A declared case that does not report is the failure mode "
            . "this lane exists to catch.\n%s",
            $id,
            CaseReport::indent($output),
        );
        $results[] = ['case' => $id, 'status' => 'no-result', 'exit' => $status];
        fwrite(STDOUT, sprintf("  %-32s NO RESULT\n", $id));
        continue;
    }

    $reported['exit'] = $status;
    $results[] = $reported;

    if ($status === 0 && ($reported['status'] ?? null) === 'passed') {
        fwrite(STDOUT, sprintf("  %-32s passed\n", $id));
        continue;
    }

    $failures[] = sprintf("Case %s failed.\n%s", $id, CaseReport::indent($output));
    fwrite(STDOUT, sprintf("  %-32s FAILED\n", $id));
}

$declared = count($manifest['cases']);
$passed = count(array_filter(
    $results,
    static fn (array $result): bool => ($result['status'] ?? null) === 'passed',
));

if ($reportPath !== null) {
    $encoded = json_encode(
        [
            'lane' => $manifest['lane'] ?? 'kumwe-deployed-artifact',
            'declared' => $declared,
            'passed' => $passed,
            'php' => PHP_VERSION,
            'memory_limit' => $memoryLimit,
            'results' => $results,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    if (is_string($encoded)) {
        @mkdir(dirname($reportPath), 0o755, true);
        file_put_contents($reportPath, $encoded . "\n");
    }
}

if ($failures !== []) {
    fwrite(STDERR, "\nThe deployed-artifact lane failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . "\n");
    }
    exit(1);
}

if ($passed !== $declared) {
    fwrite(
        STDERR,
        sprintf("The manifest declares %d cases and %d passed; a declared case did not run.\n", $declared, $passed),
    );
    exit(1);
}

fwrite(STDOUT, sprintf("\nDeployed-artifact lane passed: %d declared cases, %d executed.\n", $declared, $passed));
exit(0);
